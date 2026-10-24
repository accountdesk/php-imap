<?php
/*
* File: LineEndingTest.php
* Category: -
* Author: -
* Created: 24.10.26 21:30
* Updated: -
*
* Description:
*  Messages with bare LF line endings. Some servers (Stalwart, James, GreenMail) return a message via FETCH
*  exactly as it was stored, i.e. with LF only. The parsers must accept both line endings without changing
*  the content of the parts (attachments, sizes).
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Part;
use Webklex\PHPIMAP\Structure;

class LineEndingTest extends TestCase {

    /** @var Client $client */
    protected Client $client;

    /** @var string $binary raw bytes of the binary encoded attachment - incl. line breaks, NUL and whitespace */
    protected string $binary = "\x00\x01 bin\r\nary\n\xFF\x00 ";

    /** @var string $png bytes of the base64 encoded attachment */
    protected string $png;

    public function setUp(): void {
        $this->client = new Client(Config::make([
            "accounts" => [
                "default" => [
                    'protocol'   => 'imap',
                    'encryption' => 'ssl',
                    'username'   => 'foo@domain.tld',
                    'password'   => 'bar',
                ]
            ]
        ]));
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $protocol->method('selectFolder')->willReturn(Response::empty()->setResult(["exists" => 1]));
        $this->client->connection = $protocol;
        $this->client->openFolder("INBOX");

        // long enough for several base64 lines
        $this->png = "\x89PNG\r\n\x1A\n" . str_repeat("\x00\xFF\r\n\n", 40);
    }

    /**
     * A multipart/mixed message with a nested multipart/alternative, a base64 image, a quoted-printable text
     * attachment, a binary attachment and an attached message (which is a multipart itself)
     * @param string $nl line ending
     *
     * @return string[] [header, body]
     */
    protected function message(string $nl): array {
        $header = implode($nl, [
            "Return-Path: <sender@example.com>",
            "From: =?utf-8?Q?J=C3=BCrgen?= Sender <sender@example.com>",
            "To: \"Doe, John\" <john@example.com>,",
            " jane@example.com",
            "Subject: =?utf-8?Q?Line_endings_=C3=A4?=",
            "  folded",
            "Date: Mon, 01 Jan 2024 10:00:00 +0000",
            "Message-ID: <lf@example.com>",
            "MIME-Version: 1.0",
            "Content-Type: multipart/mixed;",
            "\tboundary=\"outer\"",
        ]) . $nl . $nl;

        $embedded = implode($nl, [
            "From: inner@example.com",
            "Subject: Inner",
            "Content-Type: multipart/alternative; boundary=\"embedded\"",
            "",
            "--embedded",
            "Content-Type: text/plain",
            "",
            "inner text",
            "--embedded--",
        ]);

        $body = implode($nl, [
            "This is a multi-part message in MIME format.",
            "",
            "--outer",
            "Content-Type: multipart/alternative; boundary=\"inner\"",
            "",
            "--inner",
            "Content-Type: text/plain; charset=utf-8",
            "Content-Transfer-Encoding: quoted-printable",
            "",
            "Gr=C3=BC=C3=9Fe,",
            "a soft =",
            "break and --inner-not-a-boundary",
            "--inner",
            "Content-Type: text/html; charset=utf-8",
            "",
            "<p>Hello</p>",
            "--inner--",
            "",
            "--outer",
            "Content-Type: image/png; name=\"image.png\"",
            "Content-Transfer-Encoding: base64",
            "Content-Disposition: attachment; filename=\"image.png\"",
            "",
            rtrim(chunk_split(base64_encode($this->png), 76, $nl)),
            "--outer",
            "Content-Type: text/plain; charset=utf-8; name=\"notes.txt\"",
            "Content-Transfer-Encoding: quoted-printable",
            "Content-Disposition: attachment; filename=\"notes.txt\"",
            "",
            "line one",
            "line =C3=A4",
            "--outer",
            "Content-Type: application/octet-stream; name=\"raw.bin\"",
            "Content-Transfer-Encoding: binary",
            "Content-Disposition: attachment; filename=\"raw.bin\"",
            "",
            $this->binary,
            "--outer",
            "Content-Type: message/rfc822",
            "Content-Disposition: attachment; filename=\"inner.eml\"",
            "",
            $embedded,
            "--outer--",
            "epilogue",
            "",
        ]);

        return [$header, $body];
    }

    /**
     * Parse like Message::make() does with the header and the body fetched from the server
     * @param string $nl
     *
     * @return Message
     */
    protected function fetched(string $nl): Message {
        [$header, $body] = $this->message($nl);

        return Message::make(1, null, $this->client, $header, $body, ["\\Seen"], IMAP::FT_PEEK, IMAP::ST_UID);
    }

    /**
     * @param Message $message
     *
     * @return Attachment[]
     */
    protected function attachments(Message $message): array {
        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[$attachment->name] = $attachment;
        }
        return $attachments;
    }

    protected function assertMessage(Message $message, string $nl): void {
        self::assertSame("Line endings ä  folded", (string)$message->subject);
        self::assertSame("Jürgen Sender", $message->from->first()->personal);
        self::assertSame(["john@example.com", "jane@example.com"], array_map(function($address) {
            return $address->mail;
        }, $message->to->all()));
        self::assertSame("Doe, John", $message->to->first()->personal);
        self::assertSame("lf@example.com", (string)$message->message_id);
        self::assertSame("outer", $message->getHeader()->getBoundary());

        self::assertSame("Grüße," . $nl . "a soft break and --inner-not-a-boundary", $message->getTextBody());
        self::assertSame("<p>Hello</p>", $message->getHTMLBody());

        // preamble and epilogue are no parts
        self::assertCount(6, $message->getStructure()->parts);
        self::assertSame([0, 1, 2, 3, 4, 5], array_map(function(Part $part) {
            return $part->part_number;
        }, $message->getStructure()->parts));

        $attachments = $this->attachments($message);
        self::assertSame(["image.png", "notes.txt", "raw.bin", "inner.eml"], array_keys($attachments));

        // the decoded content does not depend on the line endings ...
        self::assertSame($this->png, $attachments["image.png"]->content);
        self::assertSame($this->binary, $attachments["raw.bin"]->content);
        self::assertSame(strlen($this->binary), $attachments["raw.bin"]->size);
        // ... the size is the size of the transfer encoded data, as it was stored
        self::assertSame(strlen(rtrim(chunk_split(base64_encode($this->png), 76, $nl))), $attachments["image.png"]->size);
        self::assertSame("line one" . $nl . "line ä", $attachments["notes.txt"]->content);

        // the attached message is kept unchanged and can be parsed itself
        $inner = Message::fromString($attachments["inner.eml"]->content);
        self::assertSame("Inner", (string)$inner->subject);
        self::assertSame("inner text", $inner->getTextBody());
    }

    public function testCrlfMessageFromServer(): void {
        $this->assertMessage($this->fetched("\r\n"), "\r\n");
    }

    public function testLfMessageFromServer(): void {
        $this->assertMessage($this->fetched("\n"), "\n");
    }

    public function testLfMessageFromString(): void {
        [$header, $body] = $this->message("\n");
        $message = Message::fromString($header . $body);

        // fromString() converts a message without any CRLF to CRLF (documented behaviour), the binary attachment
        // contains a CRLF itself, so this message is parsed unchanged
        $this->assertMessage($message, "\n");
    }

    public function testLfOnlyMessageFromStringIsNormalized(): void {
        $message = Message::fromString("Subject: x\nFrom: a@b.c\nContent-Type: text/plain\n\nline one\nline two\n");

        self::assertSame("x", (string)$message->subject);
        self::assertSame("line one\r\nline two", $message->getTextBody());
    }

    public function testLfHeaderOnly(): void {
        $header = new Header("Subject: Folded\n subject\nX-Multi: a\nX-Multi: b\nTo: a@b.c,\n\td@e.f\n", Config::make());

        self::assertSame("Folded subject", (string)$header->subject);
        self::assertSame(["a", "b"], $header->get("x_multi")->all());
        self::assertCount(2, $header->get("to")->all());
        self::assertSame("Subject: Folded\n subject\nX-Multi: a\nX-Multi: b\nTo: a@b.c,\n\td@e.f\n", $header->raw);
    }

    public function testMixedLineEndingsInHeader(): void {
        $header = new Header("Subject: a\r\nX-One: 1\nX-Two: 2\r\n", Config::make());

        self::assertSame("a", (string)$header->subject);
        self::assertSame("1", (string)$header->get("x_one"));
        self::assertSame("2", (string)$header->get("x_two"));
    }

    public function testSplitHeaderAndBody(): void {
        self::assertSame(["A: 1\r\n", "body\r\n"], Part::splitHeaderAndBody("A: 1\r\n\r\nbody\r\n"));
        self::assertSame(["A: 1\n", "body\n"], Part::splitHeaderAndBody("A: 1\n\nbody\n"));
        // the body is returned unchanged, also if it contains blank lines
        self::assertSame(["A: 1\n", "x\n\ny"], Part::splitHeaderAndBody("A: 1\n\nx\n\ny"));
        // no header: the part starts with the blank line
        self::assertSame(["", "body"], Part::splitHeaderAndBody("\r\nbody"));
        self::assertSame(["", "body"], Part::splitHeaderAndBody("\nbody"));
        // no blank line: a header without body, or a body without header
        self::assertSame(["A: 1\r\nB: 2", ""], Part::splitHeaderAndBody("A: 1\r\nB: 2"));
        self::assertSame(["", "just text"], Part::splitHeaderAndBody("just text"));
        self::assertSame(["", ""], Part::splitHeaderAndBody(""));
    }

    public function testSplitMultipart(): void {
        // CRLF, LF, transport padding behind the delimiter, preamble and epilogue
        self::assertSame(["A: 1\r\n\r\na", "b"], Structure::splitMultipart("b1", "pre\r\n--b1\r\nA: 1\r\n\r\na\r\n--b1 \t\r\nb\r\n--b1--\r\nepi"));
        self::assertSame(["A: 1\n\na", "b"], Structure::splitMultipart("b1", "--b1\nA: 1\n\na\n--b1\nb\n--b1--\n"));
        // the line break in front of the delimiter belongs to it, further ones are content
        self::assertSame(["a\n"], Structure::splitMultipart("b1", "--b1\na\n\n--b1--"));
        // a line which only starts with the delimiter is content
        self::assertSame(["--b1x\n--b1-x"], Structure::splitMultipart("b1", "--b1\n--b1x\n--b1-x\n--b1--"));
        // the boundary is no regex
        self::assertSame(["a"], Structure::splitMultipart("=_(.*)", "--=_(.*)\na\n--=_(.*)--"));
        // missing close delimiter (truncated message)
        self::assertSame(["a", "b"], Structure::splitMultipart("b1", "--b1\na\n--b1\nb\n"));
        // delimiter missing completely: the whole body is one part
        self::assertSame(["just text"], Structure::splitMultipart("b1", "just text"));
    }

    public function testBinaryAttachmentIsNotTrimmed(): void {
        $config = Config::make();
        $part = new Part($this->binary, $config, new Header("Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: binary", $config));
        self::assertSame($this->binary, $part->content);

        $part = new Part(" \x00data\x00 ", $config, new Header("Content-Type: image/png\r\nContent-Transfer-Encoding: 8bit", $config));
        self::assertSame(" \x00data\x00 ", $part->content);

        // text is still trimmed
        $part = new Part("\r\n text \r\n", $config, new Header("Content-Type: text/plain\r\nContent-Transfer-Encoding: 8bit", $config));
        self::assertSame("text", $part->content);
        $part = new Part(" aGk= \r\n", $config, new Header("Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64", $config));
        self::assertSame("aGk=", $part->content);
    }
}
