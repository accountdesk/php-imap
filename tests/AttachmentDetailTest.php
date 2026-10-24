<?php
/*
* File: AttachmentDetailTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Attachment details (names, types, masks, accessors).
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Decoder\AttachmentDecoder;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Part;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;

class AttachmentDetailTest extends TestCase {

    /**
     * @param Config|null $config
     *
     * @return Message
     */
    protected function message(?Config $config = null): Message {
        return Message::fromString("From: a@b.c\r\nSubject: x\r\nDate: Mon, 01 Jan 2024 10:00:00 +0000\r\n\r\nBody", $config);
    }

    /**
     * @param string $headers
     * @param string $body
     * @param Message|null $message
     *
     * @return Attachment
     */
    protected function attachment(string $headers, string $body = "content", ?Message $message = null): Attachment {
        $message = $message ?? $this->message();
        $config = $message->getConfig();
        return new Attachment($message, new Part($body, $config, new Header($headers, $config), 1));
    }

    public function testBasicAttributes(): void {
        $attachment = $this->attachment("Content-Type: application/pdf; name=\"a.pdf\"\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\nContent-Transfer-Encoding: base64\r\nContent-Description: The PDF", base64_encode("%PDF"));

        self::assertSame("%PDF", $attachment->content);
        self::assertSame("%PDF", $attachment->getContent());
        self::assertSame("application/pdf", $attachment->content_type);
        self::assertSame("a.pdf", $attachment->name);
        self::assertSame("a.pdf", $attachment->filename);
        self::assertSame("attachment", $attachment->disposition);
        self::assertSame("The PDF", $attachment->description);
        self::assertSame(8, $attachment->size);
        self::assertSame(1, $attachment->part_number);
        self::assertSame(8, strlen($attachment->hash));
        self::assertSame($attachment->hash, $attachment->id);
        self::assertSame("pdf", $attachment->getExtension());
        self::assertInstanceOf(Message::class, $attachment->getMessage());
        // header attributes of the part are available as well
        self::assertArrayHasKey("content_type", $attachment->getAttributes());
    }

    public function testHashDependsOnContent(): void {
        $a = $this->attachment("Content-Type: application/pdf", "one");
        $b = $this->attachment("Content-Type: application/pdf", "two");
        $c = $this->attachment("Content-Type: application/pdf", "one");

        self::assertNotSame($a->hash, $b->hash);
        self::assertSame($a->hash, $c->hash);
    }

    public function testMagicAccessors(): void {
        $attachment = $this->attachment("Content-Type: application/pdf");

        self::assertSame("application/pdf", $attachment->getContentType());
        self::assertNull($attachment->getNotExisting());
        self::assertNull($attachment->not_existing);
        self::assertSame("custom", $attachment->setCustom("custom"));
        self::assertSame("custom", $attachment->custom);
        $attachment->other = 5;
        self::assertSame(5, $attachment->getOther());

        $this->expectException(MethodNotFoundException::class);
        $attachment->doSomething();
    }

    public function testFindType(): void {
        $map = [
            IMAP::ATTACHMENT_TYPE_TEXT => "text",
            IMAP::ATTACHMENT_TYPE_MULTIPART => "multipart",
            IMAP::ATTACHMENT_TYPE_MESSAGE => "message",
            IMAP::ATTACHMENT_TYPE_APPLICATION => "application",
            IMAP::ATTACHMENT_TYPE_AUDIO => "audio",
            IMAP::ATTACHMENT_TYPE_IMAGE => "image",
            IMAP::ATTACHMENT_TYPE_VIDEO => "video",
            IMAP::ATTACHMENT_TYPE_MODEL => "model",
            IMAP::ATTACHMENT_TYPE_OTHER => "other",
        ];
        $message = $this->message();
        $config = $message->getConfig();
        foreach ($map as $type => $expected) {
            $part = new Part("x", $config, new Header("Content-Type: application/octet-stream", $config), 1);
            $part->type = $type;
            self::assertSame($expected, (new Attachment($message, $part))->type);
        }
    }

    public function testMessageAttachmentName(): void {
        $message = $this->message();
        $config = $message->getConfig();

        $part = new Part("Subject: inner", $config, new Header("Content-Type: message/rfc822\r\nContent-Description: Forwarded message", $config), 1);
        $part->type = IMAP::ATTACHMENT_TYPE_MESSAGE;
        self::assertSame("Forwarded message", (new Attachment($message, $part))->name);

        $part = new Part("Subject: inner", $config, new Header("Content-Type: message/rfc822", $config), 1);
        $part->type = IMAP::ATTACHMENT_TYPE_MESSAGE;
        self::assertSame("rfc822", (new Attachment($message, $part))->name);

        $part = new Part("Subject: inner", $config, new Header("Content-Type: message/rfc822; name=\"fwd.eml\"", $config), 1);
        $part->type = IMAP::ATTACHMENT_TYPE_MESSAGE;
        self::assertSame("fwd.eml", (new Attachment($message, $part))->name);
    }

    public function testNameFallbacks(): void {
        // without any name the hash is used
        $attachment = $this->attachment("Content-Type: application/octet-stream", "data");
        self::assertSame($attachment->hash, $attachment->filename);
        self::assertSame($attachment->hash, $attachment->name);
        self::assertNull($attachment->getExtension());

        // the name is used for the extension if the filename has none
        $attachment = $this->attachment("Content-Type: text/plain; name=\"readme.txt\"\r\nContent-Disposition: attachment; filename=\"README\"", "data");
        self::assertSame("README", $attachment->filename);
        self::assertSame("readme.txt", $attachment->name);
        self::assertSame("txt", $attachment->getExtension());
    }

    public function testDecodeName(): void {
        $attachment = $this->attachment("Content-Type: application/octet-stream");

        self::assertSame("", $attachment->decodeName(null));
        self::assertSame("plain.txt", $attachment->decodeName("plain.txt"));
        self::assertSame("ä.txt", $attachment->decodeName("ISO-8859-1''%E4.txt"));
        self::assertSame("€ x.pdf", $attachment->decodeName("utf-8''%E2%82%AC%20x.pdf"));
        // an unknown charset prefix is left in place
        self::assertSame("foo''bar.txt", $attachment->decodeName("foo''bar.txt"));
        self::assertSame("100% sure.txt", $attachment->decodeName("100% sure.txt"));
        self::assertSame("a b.txt", $attachment->decodeName("a%20b.txt"));
        // leading dots are removed
        self::assertSame("hidden", $attachment->decodeName("..hidden"));
    }

    public function testSanitizingCanBeDisabled(): void {
        $config = Config::make(["security" => ["sanitize_filenames" => false]]);
        $attachment = $this->attachment("Content-Type: application/octet-stream", "x", $this->message($config));

        self::assertSame("../../etc/passwd", $attachment->decodeName("../../etc/passwd"));
    }

    public function testMasks(): void {
        $attachment = $this->attachment("Content-Type: image/gif\r\nContent-Transfer-Encoding: base64", base64_encode("GIF89a"));

        self::assertSame(AttachmentMask::class, $attachment->getMask());
        $mask = $attachment->mask();
        self::assertInstanceOf(AttachmentMask::class, $mask);
        self::assertSame("data:image/gif;base64,".base64_encode("GIF89a"), $mask->getImageSrc());

        $attachment->setMask("Not\\A\\Mask");
        self::assertSame(AttachmentMask::class, $attachment->getMask());
        self::assertSame(AttachmentMask::class, $attachment->setMask(AttachmentMask::class)->getMask());

        $this->expectException(MaskNotFoundException::class);
        $attachment->mask("Not\\A\\Mask");
    }

    public function testAccessors(): void {
        $attachment = $this->attachment("Content-Type: application/pdf");

        self::assertSame("list", $attachment->getOptions()["message_key"]);
        self::assertSame(["a" => 1], $attachment->setOptions(["a" => 1])->getOptions());

        $config = Config::make();
        self::assertSame($config, $attachment->setConfig($config)->getConfig());

        self::assertInstanceOf(AttachmentDecoder::class, $attachment->getDecoder());
        $decoder = new AttachmentDecoder();
        self::assertSame($decoder, $attachment->setDecoder($decoder)->getDecoder());
    }

    public function testSaveUsesFilename(): void {
        $attachment = $this->attachment("Content-Type: text/plain; name=\"note.txt\"\r\nContent-Disposition: attachment; filename=\"note.txt\"", "saved content");
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR."php-imap-".uniqid();
        mkdir($dir);
        try {
            self::assertTrue($attachment->save($dir));
            self::assertSame("saved content", file_get_contents($dir.DIRECTORY_SEPARATOR."note.txt"));
        } finally {
            array_map("unlink", glob($dir.DIRECTORY_SEPARATOR."*"));
            rmdir($dir);
        }
    }
}
