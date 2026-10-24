<?php
/*
* File: PartDetailTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Part parsing and attachment detection.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Part;

class PartDetailTest extends TestCase {

    /** @var Config $config */
    protected Config $config;

    public function setUp(): void {
        $this->config = Config::make();
    }

    /**
     * @param string $headers
     * @param string $body
     *
     * @return Part
     */
    protected function part(string $headers, string $body = "content"): Part {
        return new Part($body, $this->config, new Header($headers, $this->config), 3);
    }

    public function testPartWithoutHeaderParsesItsOwnHeaders(): void {
        $part = new Part("Content-Type: text/html; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>Hi</p>\r\n", $this->config);

        self::assertSame("text/html", $part->content_type);
        self::assertSame("html", $part->subtype);
        self::assertSame("ISO-8859-1", $part->charset);
        self::assertSame(IMAP::MESSAGE_ENC_QUOTED_PRINTABLE, $part->encoding);
        self::assertSame("<p>Hi</p>", $part->content);
        self::assertSame(9, $part->bytes);
        self::assertSame(0, $part->part_number);
        self::assertInstanceOf(Header::class, $part->getHeader());
        self::assertSame($this->config, $part->getConfig());
    }

    public function testTransferEncodings(): void {
        $map = [
            "quoted-printable" => IMAP::MESSAGE_ENC_QUOTED_PRINTABLE,
            "BASE64" => IMAP::MESSAGE_ENC_BASE64,
            "7bit" => IMAP::MESSAGE_ENC_7BIT,
            "8bit" => IMAP::MESSAGE_ENC_8BIT,
            "binary" => IMAP::MESSAGE_ENC_BINARY,
            "x-uuencode" => IMAP::MESSAGE_ENC_OTHER,
        ];
        foreach ($map as $value => $expected) {
            self::assertSame($expected, $this->part("Content-Transfer-Encoding: $value")->encoding, $value);
        }
        self::assertSame(IMAP::MESSAGE_ENC_OTHER, $this->part("Content-Type: text/plain")->encoding);
    }

    public function testIds(): void {
        self::assertSame("cid@example.com", $this->part("Content-ID: <cid@example.com>")->id);
        self::assertSame("ii_123", $this->part("X-Attachment-Id: ii_123")->id);
        // X-Attachment-Id takes precedence over Content-ID
        self::assertSame("ii_123", $this->part("Content-ID: <cid@example.com>\r\nX-Attachment-Id: ii_123")->id);
        self::assertNull($this->part("Content-Type: text/plain")->id);
    }

    public function testDescriptionAndDisposition(): void {
        $part = $this->part("Content-Description: Quarterly report\r\nContent-Disposition: attachment; filename=\"r.pdf\"");

        self::assertTrue($part->ifdescription);
        self::assertSame("Quarterly report", $part->description);
        self::assertTrue($part->ifdisposition);
        self::assertSame("attachment", $part->disposition);
        self::assertSame("r.pdf", $part->filename);
        self::assertSame(3, $part->part_number);

        $part = $this->part("Content-Type: text/plain");
        self::assertFalse($part->ifdescription);
        self::assertNull($part->description);
        self::assertFalse($part->ifdisposition);
        self::assertNull($part->disposition);
    }

    public function testSubtypeDetection(): void {
        self::assertSame("plain", $this->part("Content-Type: text/plain; charset=utf-8")->subtype);
        self::assertSame("vnd.ms-excel", $this->part("Content-Type: application/vnd.ms-excel; name=\"a.xls\"")->subtype);
        self::assertNull($this->part("Content-Type: broken")->subtype);
        self::assertNull($this->part("Subject: none")->subtype);
        self::assertNull($this->part("Subject: none")->content_type);
    }

    public function testIsAttachment(): void {
        $cases = [
            "Content-Type: text/plain" => false,
            "Content-Type: text/html" => false,
            "Content-Type: text/plain\r\nContent-Disposition: attachment" => true,
            "Content-Type: text/plain\r\nContent-Disposition: inline" => false,
            "Content-Type: text/plain; name=\"notes.txt\"" => true,
            "Content-Type: text/html\r\nContent-Disposition: inline; filename=\"page.html\"" => true,
            "Content-Type: text/plain\r\nContent-Disposition: unknown" => false,
            "Content-Type: text/calendar" => true,
            "Content-Type: application/pdf" => true,
            "Content-Type: image/png\r\nContent-Disposition: inline" => false,
            "Content-Type: image/png\r\nContent-Disposition: inline\r\nContent-ID: <img1>" => true,
            "Subject: no content type" => false,
        ];
        foreach ($cases as $headers => $expected) {
            self::assertSame($expected, $this->part($headers)->isAttachment(), $headers);
        }
    }

    public function testConfiguredDispositions(): void {
        $this->config->set("options.dispositions", ["attachment"]);

        // "inline" is no longer a valid disposition: inline text stays a body part
        self::assertFalse($this->part("Content-Type: text/plain\r\nContent-Disposition: inline")->isAttachment());
        self::assertTrue($this->part("Content-Type: text/plain\r\nContent-Disposition: attachment")->isAttachment());
    }

    public function testDispositionIsCaseInsensitive(): void {
        self::assertFalse($this->part("Content-Type: text/plain\r\nContent-Disposition: INLINE")->isAttachment());
    }
}
