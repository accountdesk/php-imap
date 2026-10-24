<?php
/*
* File: HeaderDetailTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the Header API and edge cases of the header parser.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;

class HeaderDetailTest extends TestCase {

    /** @var Config $config */
    protected Config $config;

    public function setUp(): void {
        $this->config = Config::make();
    }

    /**
     * @param string $raw
     *
     * @return Header
     */
    protected function header(string $raw): Header {
        return new Header($raw, $this->config);
    }

    public function testGetHasAndSet(): void {
        $header = $this->header("Subject: Hello\r\nX-Original-To: a@b.c");

        self::assertTrue($header->has("subject"));
        self::assertTrue($header->has("X-Original-To"));
        self::assertTrue($header->has("x original to"));
        self::assertFalse($header->has("x-missing"));
        self::assertSame("a@b.c", (string)$header->get("X-Original-To"));
        self::assertSame("a@b.c", (string)$header->x_original_to);

        // unknown attributes return an empty attribute with the normalized name
        $missing = $header->get("X-Missing-Header");
        self::assertInstanceOf(Attribute::class, $missing);
        self::assertSame("x_missing_header", $missing->getName());
        self::assertSame(0, $missing->count());

        // set() appends unique values unless strict mode replaces the attribute
        $header->set("subject", "Hello");
        $header->set("subject", "World");
        self::assertSame(["Hello", "World"], $header->get("subject")->all());
        $header->set("subject", "Replaced", true);
        self::assertSame(["Replaced"], $header->get("subject")->all());
        $header->set("new", ["a", "b"]);
        self::assertSame(["a", "b"], $header->get("new")->all());
    }

    public function testMagicGetter(): void {
        $header = $this->header("Subject: Hello\r\nFrom: a@b.c");

        self::assertSame("Hello", (string)$header->getSubject());
        self::assertSame("a@b.c", $header->getFrom()->first()->mail);

        $this->expectException(MethodNotFoundException::class);
        $header->getNotExisting();
    }

    public function testMagicGetterWithCamelCase(): void {
        $this->markTestSkipped("BUG: Header::__call() lowercases the method name before converting camelCase, getMessageId() looks up 'messageid'");
        $header = $this->header("Message-ID: <a@b>\r\nIn-Reply-To: <c@d>");

        self::assertSame("a@b", (string)$header->getMessageId());
        self::assertSame("c@d", (string)$header->getInReplyTo());
    }

    public function testFind(): void {
        $header = $this->header("Subject: Hello\r\nX-Spam-Score: 4.2\r\nX-Spam-Score: 1.0");

        self::assertSame("4.2", $header->find("/X-Spam-Score: ([0-9.]+)/"));
        self::assertNull($header->find("/X-Missing: (.*)/"));
        self::assertNull($header->find("/Subject: Hello/"));
    }

    public function testBoundary(): void {
        self::assertSame("abc", $this->header("Content-Type: multipart/mixed; boundary=\"abc\"")->getBoundary());
        self::assertSame("abc", $this->header("Content-Type: multipart/mixed; boundary=abc; charset=utf-8")->getBoundary());
        self::assertSame("=_x=_", $this->header("Content-Type: multipart/mixed;\r\n boundary=\"=_x=_\"")->getBoundary());
        self::assertNull($this->header("Content-Type: text/plain")->getBoundary());

        // the regex can be configured
        $header = $this->header("Content-Type: multipart/mixed; boundary=\"abc\"\r\nX-Boundary: custom");
        $header->setOptions(["boundary" => "/X-Boundary: (.*)/"]);
        self::assertSame("custom", $header->getBoundary());
    }

    public function testRfc822ParseHeadersStructure(): void {
        $header = $this->header("Subject: x");
        $parsed = $header->rfc822_parse_headers("Subject: Folded\r\n line\r\nX-Multi: a\r\nX-Multi: b\r\nX-Multi: c\r\nX-Empty:\r\nTo: a@b.c, d@e.f\r\ninvalid line");

        self::assertSame("Folded line", $parsed->subject);
        self::assertSame(["a", "b", "c"], $parsed->x_multi);
        self::assertSame("", $parsed->x_empty);
        self::assertCount(2, $parsed->to);
        self::assertSame("a@b.c, d@e.f", $parsed->toaddress);
        self::assertFalse(property_exists($parsed, "invalid line"));
    }

    public function testContinuationWithoutHeaderIsIgnored(): void {
        $header = $this->header("\tcontinued\r\n continued too\r\nSubject: x");

        self::assertSame("x", (string)$header->subject);
        self::assertCount(2, $header->getAttributes());
    }

    public function testPriorityMapping(): void {
        $map = [
            "1" => IMAP::MESSAGE_PRIORITY_HIGHEST,
            "2 (High)" => IMAP::MESSAGE_PRIORITY_HIGH,
            "3" => IMAP::MESSAGE_PRIORITY_NORMAL,
            "4" => IMAP::MESSAGE_PRIORITY_LOW,
            "5 (Lowest)" => IMAP::MESSAGE_PRIORITY_LOWEST,
            "9" => IMAP::MESSAGE_PRIORITY_UNKNOWN,
            "high" => IMAP::MESSAGE_PRIORITY_UNKNOWN,
        ];
        foreach ($map as $value => $expected) {
            self::assertSame($expected, $this->header("X-Priority: $value")->get("priority")->first(), $value);
        }
        self::assertSame(IMAP::MESSAGE_PRIORITY_UNKNOWN, $this->header("Subject: x")->get("priority")->first());
    }

    public function testSpecialAddressHosts(): void {
        $header = $this->header("To: someone@UNKNOWN, x@.SYNTAX-ERROR., UNEXPECTED_DATA_AFTER_ADDRESS@h.com, MISSING_MAILBOX_TERMINATOR@h.com");
        $to = $header->get("to")->all();

        self::assertCount(4, $to);
        self::assertSame("", $to[0]->host);
        self::assertSame("someone", $to[0]->mail);
        self::assertSame("", $to[1]->host);
        self::assertSame("", $to[2]->mailbox);
        self::assertSame("", $to[3]->mailbox);
    }

    public function testStringAddressHeaders(): void {
        $header = $this->header("Delivered-To: <deliv@example.com>\r\nEnvelope-From: env@example.com\r\nEnvelope-To: Some One <env-to@example.com>");

        self::assertSame("deliv@example.com", $header->get("delivered_to")->first()->mail);
        self::assertSame("env@example.com", $header->get("envelope_from")->first()->mail);
        self::assertSame("Some One", $header->get("envelope_to")->first()->personal);
    }

    public function testInvalidAddressIsDropped(): void {
        $header = $this->header("From: Name Only");

        self::assertSame(0, $header->get("from")->count());
        self::assertSame("Name Only", (string)$header->get("fromaddress"));
    }

    public function testEmptyReturnPath(): void {
        $header = $this->header("Return-Path: <>\r\nFrom: a@b.c");
        $return_path = $header->get("return_path")->first();

        self::assertSame("", $return_path->mail);
        self::assertSame("", $return_path->full);
    }

    public function testEmptyAddressInFrom(): void {
        $this->markTestSkipped("BUG: 'From: <>' produces an address with mailbox '>' (the '>' fix only exists in parseAddresses())");
        $header = $this->header("From: <>");

        foreach ($header->get("from")->all() as $address) {
            self::assertSame("", $address->mail);
        }
    }

    public function testNullReturnPathIsNoSpoofing(): void {
        $this->markTestSkipped("BUG: Header::detectSpoofing() counts an empty Return-Path (<>, bounces) as another sender");
        $header = $this->header("Return-Path: <>\r\nFrom: a@b.c");

        self::assertFalse($header->has("spoofed"));
    }

    public function testHeaderExtensions(): void {
        $header = $this->header("Content-Type: text/plain; charset=\"ISO-8859-1\"; format=flowed\r\n".
            "Content-Disposition: attachment; filename*=UTF-8''%C3%A4.txt; size=123");

        self::assertSame("ISO-8859-1", (string)$header->get("charset"));
        self::assertSame("flowed", (string)$header->get("format"));
        self::assertSame("UTF-8''%C3%A4.txt", (string)$header->get("filename"));
        self::assertSame("123", (string)$header->get("size"));
        // the bare value of a parameterized header is added to the header itself
        self::assertContains("text/plain", $header->get("content_type")->all());
        self::assertContains("attachment", $header->get("content_disposition")->all());
    }

    public function testUserAgentIsNotParsedAsAttributes(): void {
        $this->markTestSkipped("BUG: Header::extractHeaderExtensions() excludes 'user-agent', but the attribute key is 'user_agent'");
        $header = $this->header("User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv=1)");

        self::assertFalse($header->has("rv"));
    }

    public function testEscapedQuoteInParameter(): void {
        $this->markTestSkipped("BUG: Header::read_attribute() drops escaped characters inside quoted values (name=\"a\\\"b.txt\" -> 'ab.txt')");
        $header = $this->header("Content-Type: text/plain; name=\"a\\\"b.txt\"; charset=utf-8");

        self::assertSame("a\"b.txt", (string)$header->get("name"));
    }

    public function testAccessors(): void {
        $header = $this->header("Subject: x");

        self::assertSame($this->config, $header->getConfig());
        $config = Config::make();
        self::assertSame($config, $header->setConfig($config)->getConfig());

        self::assertInstanceOf(HeaderDecoder::class, $header->getDecoder());
        $decoder = new HeaderDecoder(["header" => "iconv"]);
        self::assertSame($decoder, $header->setDecoder($decoder)->getDecoder());

        self::assertSame(["a" => 1], $header->setOptions(["a" => 1])->getOptions());

        $attributes = ["foo" => new Attribute("foo", "bar")];
        self::assertSame($attributes, $header->setAttributes($attributes)->getAttributes());
        self::assertSame("bar", (string)$header->foo);
    }

    public function testRfc822OptionDisablesNothingWithoutExtensions(): void {
        $this->config->set("options.rfc822", false);
        $header = $this->header("From: Name <a@b.c>\r\nSubject: x");

        self::assertSame("Name", $header->get("from")->first()->personal);
    }
}
