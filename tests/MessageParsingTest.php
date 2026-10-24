<?php
/*
* File: MessageParsingTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Message::fromString() / fromFile() with edge case messages.
*/

namespace Tests;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Decoder\MessageDecoder;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Exceptions\SpoofingAttemptDetectedException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Structure;
use Webklex\PHPIMAP\Support\FlagCollection;
use Webklex\PHPIMAP\Support\Masks\MessageMask;

class MessageParsingTest extends TestCase {

    /**
     * Build a simple raw message
     * @param string $extra_headers
     * @param string $body
     * @param string $content_type
     *
     * @return string
     */
    protected function raw(string $extra_headers = "", string $body = "Hello", string $content_type = "text/plain; charset=utf-8"): string {
        return "From: Sender <sender@example.com>\r\n".
            "To: rcpt@example.com\r\n".
            "Subject: Test\r\n".
            "Message-ID: <test@example.com>\r\n".
            "Date: Mon, 01 Jan 2024 10:00:00 +0000\r\n".
            $extra_headers.
            "Content-Type: $content_type\r\n".
            "\r\n".
            $body;
    }

    public function testLfLineEndingsAreNormalized(): void {
        $message = Message::fromString(str_replace("\r\n", "\n", $this->raw("", "line one\nline two")));

        self::assertSame("Test", (string)$message->subject);
        self::assertSame("line one\r\nline two", $message->getTextBody());
        self::assertSame(0, $message->uid);
        self::assertNull($message->getClient());
    }

    public function testTrailingWhitespaceOfBodyIsTrimmed(): void {
        $message = Message::fromString($this->raw("", "\r\n  Hello  \r\n\r\n"));

        self::assertSame("Hello", $message->getTextBody());
    }

    public function testEmptyBody(): void {
        $message = Message::fromString($this->raw("", ""));

        self::assertFalse($message->hasTextBody());
        self::assertSame("", $message->getTextBody());
        self::assertFalse($message->hasHTMLBody());
        self::assertSame("", $message->getHTMLBody());
        self::assertFalse($message->hasAttachments());
    }

    public function testHeaderOnlyMessage(): void {
        $message = Message::fromString("From: sender@example.com\r\nSubject: Only headers\r\n");

        self::assertSame("Only headers", (string)$message->subject);
        self::assertSame("sender@example.com", $message->from->first()->mail);
    }

    public function testHtmlOnlyMessage(): void {
        $message = Message::fromString($this->raw("", "<b>Hi</b>", "text/html; charset=utf-8"));

        self::assertFalse($message->hasTextBody());
        self::assertTrue($message->hasHTMLBody());
        self::assertSame("<b>Hi</b>", $message->getHTMLBody());
        self::assertSame("", $message->getTextBody());
        self::assertSame(["html" => "<b>Hi</b>"], $message->getBodies());
    }

    public function testTextPartWithOtherSubtypeIsAnAttachment(): void {
        $message = Message::fromString($this->raw("", "BEGIN:VCALENDAR", "text/calendar; charset=utf-8"));

        self::assertSame([], $message->getBodies());
        self::assertSame(1, $message->getAttachments()->count());
        self::assertSame("text/calendar", $message->getAttachments()->first()->content_type);
        self::assertSame("BEGIN:VCALENDAR", $message->getAttachments()->first()->content);
    }

    public function testBase64AndQuotedPrintableBodies(): void {
        $message = Message::fromString($this->raw("Content-Transfer-Encoding: base64\r\n", base64_encode("Grüße")));
        self::assertSame("Grüße", $message->getTextBody());

        $message = Message::fromString($this->raw("Content-Transfer-Encoding: QUOTED-PRINTABLE\r\n", "Gr=C3=BC=C3=9Fe=\r\n!"));
        self::assertSame("Grüße!", $message->getTextBody());
    }

    public function testUnknownTransferEncodingIsPassedThrough(): void {
        $message = Message::fromString($this->raw("Content-Transfer-Encoding: x-uuencode\r\n", "begin 644 file"));

        self::assertSame("begin 644 file", $message->getTextBody());
    }

    public function testMultipleTextPartsAreConcatenated(): void {
        $body = "--b\r\nContent-Type: text/plain\r\n\r\nfirst\r\n".
            "--b\r\nContent-Type: text/plain\r\n\r\n\r\n".
            "--b\r\nContent-Type: text/plain\r\n\r\nsecond\r\n--b--\r\n";
        $message = Message::fromString($this->raw("", $body, 'multipart/mixed; boundary="b"'));

        self::assertSame("first\nsecond", $message->getTextBody());
    }

    public function testMultipartWithoutBoundaryThrows(): void {
        $this->expectException(MessageContentFetchingException::class);
        Message::fromString($this->raw("", "--x\r\nContent-Type: text/plain\r\n\r\nfoo\r\n--x--", "multipart/mixed"));
    }

    public function testMultipartWithUnusedBoundaryHasSinglePart(): void {
        // the boundary does not occur in the body: the whole body is treated as one part
        $message = Message::fromString($this->raw("", "just text", 'multipart/mixed; boundary="nothere"'));

        self::assertCount(1, $message->getStructure()->parts);
        self::assertSame(IMAP::MESSAGE_TYPE_MULTIPART, $message->getStructure()->type);
    }

    public function testNestedMultipartPartNumbers(): void {
        $body = "--outer\r\n".
            "Content-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n".
            "--inner\r\nContent-Type: text/plain\r\n\r\nplain\r\n".
            "--inner\r\nContent-Type: text/html\r\n\r\n<p>html</p>\r\n".
            "--inner--\r\n\r\n".
            "--outer\r\n".
            "Content-Type: application/octet-stream; name=\"a.bin\"\r\n".
            "Content-Disposition: attachment; filename=\"a.bin\"\r\n".
            "Content-Transfer-Encoding: base64\r\n\r\n".
            base64_encode("\x00\x01\x02")."\r\n".
            "--outer--\r\n";
        $message = Message::fromString($this->raw("", $body, 'multipart/mixed; boundary="outer"'));

        self::assertSame("plain", $message->getTextBody());
        self::assertSame("<p>html</p>", $message->getHTMLBody());
        $parts = $message->getStructure()->parts;
        self::assertCount(3, $parts);
        self::assertSame([0, 1, 2], array_map(function($part) { return $part->part_number; }, $parts));

        $attachment = $message->getAttachments()->first();
        self::assertSame("a.bin", $attachment->name);
        self::assertSame("\x00\x01\x02", $attachment->content);
        self::assertSame(2, $attachment->part_number);
        self::assertSame(3, strlen($attachment->getContent()));
    }

    public function testMaxMimeDepth(): void {
        $config = Config::make(["options" => ["max_mime_depth" => 1]]);
        $body = "--a\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n".
            "--b\r\nContent-Type: multipart/mixed; boundary=\"c\"\r\n\r\n".
            "--c\r\nContent-Type: text/plain\r\n\r\ndeep\r\n--c--\r\n--b--\r\n--a--\r\n";
        $message = Message::fromString($this->raw("", $body, 'multipart/mixed; boundary="a"'), $config);

        // nesting deeper than allowed is not parsed any further: the rest is kept as one part
        $parts = $message->getStructure()->parts;
        self::assertCount(1, $parts);
        self::assertSame("--c\r\nContent-Type: text/plain\r\n\r\ndeep\r\n--c--", $parts[0]->content);
        self::assertFalse($message->hasTextBody());
    }

    public function testFromFileWithoutFile(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to read file");
        Message::fromFile(__DIR__ . "/messages/does-not-exist.eml");
    }

    public function testDuplicateAttachmentIdsReplaceEachOther(): void {
        $attachment = "Content-Type: image/png; name=\"x.png\"\r\n".
            "Content-ID: <same@id>\r\n".
            "Content-Disposition: inline; filename=\"x.png\"\r\n".
            "Content-Transfer-Encoding: base64\r\n\r\n";
        $body = "--b\r\nContent-Type: text/plain\r\n\r\ntext\r\n".
            "--b\r\n".$attachment.base64_encode("one")."\r\n".
            "--b\r\n".$attachment.base64_encode("two")."\r\n".
            "--b--\r\n";
        $message = Message::fromString($this->raw("", $body, 'multipart/related; boundary="b"'));

        // the first attachment is pushed, the second one with the same id ends up under that id
        $contents = $message->getAttachments()->map(function($a) { return $a->content; })->all();
        self::assertContains("one", $contents);
        self::assertContains("two", $contents);
    }

    public function testEmptyAttachmentIsIgnored(): void {
        $body = "--b\r\nContent-Type: text/plain\r\n\r\ntext\r\n".
            "--b\r\nContent-Type: application/pdf; name=\"empty.pdf\"\r\nContent-Disposition: attachment; filename=\"empty.pdf\"\r\n\r\n\r\n".
            "--b--\r\n";
        $message = Message::fromString($this->raw("", $body, 'multipart/mixed; boundary="b"'));

        self::assertSame(0, $message->getAttachments()->count());
    }

    public function testInvalidDateThrows(): void {
        $raw = str_replace("Mon, 01 Jan 2024 10:00:00 +0000", "not a valid date", $this->raw());
        $this->expectException(InvalidMessageDateException::class);
        Message::fromString($raw);
    }

    public function testFallbackDateIsUsedForInvalidDates(): void {
        $config = Config::make(["options" => ["fallback_date" => "2000-01-01 00:00:00"]]);
        $raw = str_replace("Mon, 01 Jan 2024 10:00:00 +0000", "not a valid date", $this->raw());
        $message = Message::fromString($raw, $config);

        self::assertSame("2000-01-01 00:00:00", $message->date->first()->format("Y-m-d H:i:s"));
    }

    public function testDateVariants(): void {
        $dates = [
            "Mon, 01 Jan 2024 10:00:00 GMT" => "2024-01-01 10:00:00",
            "1 Jan 2024 10:00:00 -0230" => "2024-01-01 12:30:00",
            "Mon, 01 Jan 2024 10:00:00 +0000 (Coordinated Universal Time)" => "2024-01-01 10:00:00",
            "Mon,&nbsp;01 Jan 2024 10:00:00 +0100" => "2024-01-01 09:00:00",
            "2024.01.01-10.00.00" => "2024-01-01 10:00:00",
            "Mon, 1 Jan 2024 10:00:00 +0100 (CET)" => "2024-01-01 09:00:00",
        ];
        foreach ($dates as $date => $expected) {
            $raw = str_replace("Mon, 01 Jan 2024 10:00:00 +0000", $date, $this->raw());
            $message = Message::fromString($raw);
            self::assertSame($expected, $message->date->first()->setTimezone("UTC")->format("Y-m-d H:i:s"), $date);
        }
    }

    public function testSpoofingDetection(): void {
        $message = Message::fromString($this->raw("Reply-To: other@example.org\r\n"));
        self::assertTrue($message->spoofed->first());

        $message = Message::fromString($this->raw());
        self::assertFalse($message->getHeader()->has("spoofed"));

        $config = Config::make(["security" => ["detect_spoofing" => false]]);
        $message = Message::fromString($this->raw("Reply-To: other@example.org\r\n"), $config);
        self::assertFalse($message->getHeader()->has("spoofed"));
    }

    public function testSpoofingDetectionThrowsWhenConfigured(): void {
        $config = Config::make(["security" => ["detect_spoofing_exception" => true]]);

        try {
            Message::fromString($this->raw("Reply-To: other@example.org\r\n"), $config);
            $this->fail("Expected SpoofingAttemptDetectedException");
        } catch (SpoofingAttemptDetectedException $e) {
            self::assertStringContainsString("sender@example.com", $e->getMessage());
            self::assertStringContainsString("other@example.org", $e->getMessage());
        }
    }

    public function testSameSenderInSeveralHeadersIsNoSpoofing(): void {
        $message = Message::fromString($this->raw("Return-Path: <sender@example.com>\r\nReply-To: sender@example.com\r\n"));

        self::assertFalse($message->getHeader()->has("spoofed"));
    }

    public function testSaveWritesHeaderAndBody(): void {
        $raw = $this->raw("", "Saved body");
        $message = Message::fromString($raw);
        $file = tempnam(sys_get_temp_dir(), "php-imap");
        try {
            self::assertSame(strlen($raw), $message->save($file));
            self::assertSame($raw, file_get_contents($file));

            $reloaded = Message::fromFile($file);
            self::assertSame("Saved body", $reloaded->getTextBody());
            self::assertSame((string)$message->subject, (string)$reloaded->subject);
        } finally {
            unlink($file);
        }
    }

    public function testRawBodyAndHeader(): void {
        $message = Message::fromString($this->raw("", "Raw"));

        self::assertSame("Raw", $message->getRawBody());
        self::assertInstanceOf(Header::class, $message->getHeader());
        self::assertInstanceOf(Structure::class, $message->getStructure());
        self::assertStringStartsWith("From: Sender", $message->getHeader()->raw);
    }

    public function testMagicAccessors(): void {
        $message = Message::fromString($this->raw());

        self::assertSame("Test", (string)$message->getSubject());
        self::assertSame("test@example.com", (string)$message->getMessageId());
        self::assertSame(0, $message->getUid());

        // only existing attributes can be set
        $message->setUid(5);
        self::assertSame(5, $message->uid);
        self::assertSame(5, $message->setUid(5)->uid);
        $message->custom = "value";
        self::assertSame("changed", $message->setCustom("changed"));
        self::assertSame("changed", $message->custom);

        try {
            $message->setNotExisting("x");
            $this->fail("Expected MethodNotFoundException");
        } catch (MethodNotFoundException $e) {
            self::assertStringContainsString("setNotExisting", $e->getMessage());
        }

        $this->expectException(MethodNotFoundException::class);
        $message->doSomething();
    }

    public function testAttributesMergeHeaderAttributes(): void {
        $message = Message::fromString($this->raw());
        $attributes = $message->getAttributes();

        self::assertArrayHasKey("uid", $attributes);
        self::assertArrayHasKey("subject", $attributes);
        self::assertArrayHasKey("from", $attributes);
    }

    public function testMasks(): void {
        $message = Message::fromString($this->raw());

        self::assertSame(MessageMask::class, $message->getMask());
        self::assertInstanceOf(MessageMask::class, $message->mask());

        // unknown classes are ignored by setMask() but rejected by mask()
        $message->setMask("Not\\A\\Class");
        self::assertSame(MessageMask::class, $message->getMask());

        $this->expectException(MaskNotFoundException::class);
        $message->mask("Not\\A\\Class");
    }

    public function testUnknownMessageMaskInConfigThrows(): void {
        $config = Config::make();
        $config->set("masks", ["message" => "Not\\A\\Class"]);

        $this->expectException(MaskNotFoundException::class);
        Message::fromString($this->raw(), $config);
    }

    public function testFlags(): void {
        $message = Message::fromString($this->raw());
        $message->parseRawFlags(["\\Seen", "\\Flagged", "\\Unknown", "draft"]);

        self::assertTrue($message->hasFlag("Seen"));
        self::assertTrue($message->hasFlag("\\flagged"));
        self::assertTrue($message->hasFlag("draft"));
        // flags which are not configured are dropped
        self::assertFalse($message->hasFlag("unknown"));
        self::assertSame(["seen" => "Seen", "flagged" => "Flagged", "draft" => "draft"], $message->getFlags()->all());
        self::assertSame($message->getFlags(), $message->flags());

        // without a list of available flags every flag is accepted
        $message->setAvailableFlags(null);
        $message->parseRawFlags(["\\Unknown"]);
        self::assertTrue($message->hasFlag("unknown"));

        $message->setFlags(FlagCollection::make(["x" => "X"]));
        self::assertTrue($message->hasFlag("x"));
        self::assertSame(["recent", "flagged"], $message->setAvailableFlags(["recent", "flagged"])->getAvailableFlags());
    }

    public function testIs(): void {
        $a = Message::fromString($this->raw());
        $b = Message::fromString($this->raw());
        $c = Message::fromString(str_replace("Subject: Test", "Subject: Other", $this->raw()));

        self::assertTrue($a->is($b));
        self::assertFalse($a->is($c));
        self::assertFalse($a->is());

        $b->setUid(9);
        self::assertFalse($a->is($b));
    }

    public function testOptionsAndConfigAccessors(): void {
        $message = Message::fromString($this->raw());

        self::assertSame("list", $message->getOptions()["message_key"]);
        self::assertSame(["foo" => "bar"], $message->setOptions(["foo" => "bar"])->getOptions());

        $config = Config::make();
        self::assertSame($config, $message->setConfig($config)->getConfig());

        $decoder = new MessageDecoder();
        self::assertSame($decoder, $message->setDecoder($decoder)->getDecoder());

        self::assertSame("INBOX", $message->setFolderPath("INBOX")->getFolderPath());
        self::assertSame($message->getAttachments(), $message->attachments());

        $attachments = $message->getAttachments();
        self::assertSame($attachments, $message->setAttachments($attachments)->getAttachments());
    }

    public function testSequenceHandling(): void {
        $message = Message::fromString($this->raw());

        // fromString() uses the configured default sequence (ST_UID) for the sequence id
        $message->setSequence(IMAP::ST_UID);
        $message->setUid(12);
        self::assertSame(12, $message->getSequenceId());

        $message->setSequence(IMAP::ST_MSGN);
        $message->setMsgn(3, 1);
        self::assertSame(3, $message->getSequenceId());
        self::assertSame(1, $message->msglist);

        $message->setSequenceId(7, 2);
        self::assertSame(7, $message->msgn);

        $message->setSequence(IMAP::ST_UID);
        $message->setSequenceId(8, 4);
        self::assertSame(8, $message->uid);
        self::assertSame(4, $message->msglist);

        // null falls back to the configured default
        $message->setSequence(null);
        self::assertSame(IMAP::ST_UID, $message->getSequence());
    }

    public function testFetchOptionDefaults(): void {
        $message = Message::fromString($this->raw());

        self::assertSame(IMAP::FT_PEEK, $message->setFetchOption(null)->getFetchOptions());
        self::assertSame(IMAP::FT_UID, $message->setFetchOption(IMAP::FT_UID)->getFetchOptions());
        // invalid types are ignored
        self::assertSame(IMAP::FT_UID, $message->setFetchOption("foo")->getFetchOptions());

        self::assertFalse($message->setFetchBodyOption(false)->getFetchBodyOption());
        self::assertTrue($message->setFetchBodyOption(null)->getFetchBodyOption());
        self::assertFalse($message->setFetchFlagsOption(false)->getFetchFlagsOption());
        self::assertTrue($message->setFetchFlagsOption(null)->getFetchFlagsOption());

        $config = Config::make();
        $config->set("options.fetch_body", "yes");
        $config->set("options.fetch_flags", "yes");
        $config->set("options.fetch", "peek");
        $message->setConfig($config);
        // invalid config values fall back to the defaults
        self::assertTrue($message->setFetchBodyOption(null)->getFetchBodyOption());
        self::assertTrue($message->setFetchFlagsOption(null)->getFetchFlagsOption());
        self::assertSame(IMAP::FT_UID, $message->setFetchOption(null)->getFetchOptions());
    }

    public function testDateAttributeIsCarbon(): void {
        $message = Message::fromString($this->raw());

        self::assertInstanceOf(Carbon::class, $message->date->first());
        self::assertSame("2024-01-01", $message->date->toDate()->format("Y-m-d"));
    }
}
