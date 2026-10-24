<?php
/*
* File: HeaderAddressTest.php
* Category: -
* Author: -
* Created: 24.10.26 21:00
* Updated: -
*
* Description:
*  Offline tests for the address list parser of the Header. The library parses address lists itself (with and
*  without ext-imap), so the results must not depend on the extension.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Header;

class HeaderAddressTest extends TestCase {

    /** @var Config $config */
    protected Config $config;

    public function setUp(): void {
        $this->config = Config::make();
    }

    /**
     * Parse "To: $list" and return [personal, mailbox, host] of every address
     * @param string $list
     *
     * @return array
     */
    protected function to(string $list): array {
        $header = new Header("To: $list\r\n", $this->config);

        return array_map(function(Address $address) {
            return [$address->personal, $address->mailbox, $address->host];
        }, $header->get("to")->all());
    }

    /**
     * Address lists which c-client (ext-imap) parses the same way - compared with imap_rfc822_parse_adrlist()
     * where the extension is available
     */
    public function testValidAddressLists(): void {
        $cases = [
            "a@b.c"                                         => [["", "a", "b.c"]],
            "<a@b.c>"                                       => [["", "a", "b.c"]],
            "Max Mustermann <max@example.de>"               => [["Max Mustermann", "max", "example.de"]],
            "\"Doe, John\" <john@x.com>, jane@x.com"        => [["Doe, John", "john", "x.com"], ["", "jane", "x.com"]],
            "\"This one: is \\\"right\\\"\" <ding@dong.com>" => [["This one: is \"right\"", "ding", "dong.com"]],
            "\"Müller, Hans\" <h@m.de>"                     => [["Müller, Hans", "h", "m.de"]],
            "Jürgen Müller <j@x.de>"                        => [["Jürgen Müller", "j", "x.de"]],
            "Peter O'Neil <p@o.ie>"                         => [["Peter O'Neil", "p", "o.ie"]],
            "\"a@b.c\" <a@b.c>"                             => [["a@b.c", "a", "b.c"]],
            // a comment is the display name if there is none
            "MAILER-DAEMON@mta.example.com (Mail Delivery System)" => [["Mail Delivery System", "MAILER-DAEMON", "mta.example.com"]],
            "a@b.c (Comment) , d@e.f"                       => [["Comment", "a", "b.c"], ["", "d", "e.f"]],
            // source routes are dropped
            "<@route,@r2:a@b.c>"                            => [["", "a", "b.c"]],
            // quoted local parts and domain literals
            "\"quoted local\"@b.c"                          => [["", "quoted local", "b.c"]],
            "x@[1.2.3.4]"                                   => [["", "x", "[1.2.3.4]"]],
            "a@b"                                           => [["", "a", "b"]],
            // empty list items are ignored
            ",a@b.c,,"                                      => [["", "a", "b.c"]],
            "<a@b.c>, <d@e.f>"                              => [["", "a", "b.c"], ["", "d", "e.f"]],
            // display name directly in front of the angle bracket
            "Name<a@b.c>"                                   => [["Name", "a", "b.c"]],
            // group members, the group name is no address
            "Group: a@b.c, \"X Y\" <d@e.f>;, z@z.z"         => [["", "a", "b.c"], ["X Y", "d", "e.f"], ["", "z", "z.z"]],
        ];
        foreach ($cases as $list => $expected) {
            self::assertSame($expected, $this->to($list), $list);
            if (extension_loaded('imap')) {
                $imap = array_map(function($address) {
                    return [$address->personal ?? "", $address->mailbox, $address->host];
                }, array_values(array_filter(\imap_rfc822_parse_adrlist($list, ""), function($address) {
                    // c-client returns group start/end markers without host
                    return isset($address->host);
                })));
                self::assertSame($expected, $imap, "imap_rfc822_parse_adrlist(): " . $list);
            }
        }
    }

    /**
     * Invalid or unusual lists, for which c-client returns placeholder addresses
     */
    public function testInvalidAddressLists(): void {
        $cases = [
            // no address at all
            "Name Only"                       => [],
            ""                                => [],
            " "                               => [],
            // an address without host is kept as mailbox
            "no_host"                         => [["", "no_host", ""]],
            // null address
            "<>"                              => [["", "", ""]],
            "\"Undisclosed Recipients\" <>"   => [["Undisclosed Recipients", "", ""]],
            // empty groups
            "undisclosed-recipients:;"        => [],
            "Undisclosed recipients: ;"       => [],
            // missing angle brackets / missing closing bracket / trailing garbage
            "John Doe john@x.com"             => [["John Doe", "john", "x.com"]],
            "John <john@x.com"                => [["John", "john", "x.com"]],
            "Name <a@b.c> trailing"           => [["Name", "a", "b.c"]],
            // unquoted display name containing an @
            "name@host.com <name@host.com>"   => [["name@host.com", "name", "host.com"]],
            // Outlook style separator
            "a@b.c; d@e.f"                    => [["", "a", "b.c"], ["", "d", "e.f"]],
            // single quotes around the display name are removed, an apostrophe inside is kept
            "'Single Quoted' <s@x.org>"       => [["Single Quoted", "s", "x.org"]],
            "'O'Neil' <o@x.ie>"               => [["O'Neil", "o", "x.ie"]],
            // nested comments and comments inside quoted strings
            "a@b.c (outer (inner) text)"      => [["outer (inner) text", "a", "b.c"]],
            "\"Name (not a comment)\" <a@b.c>" => [["Name (not a comment)", "a", "b.c"]],
            // a comma inside a comment does not split the list
            "a@b.c (Doe, John)"               => [["Doe, John", "a", "b.c"]],
            // whitespace is collapsed outside of quoted strings only
            "Max   \t Mustermann <m@x.de>"     => [["Max Mustermann", "m", "x.de"]],
            "\"Max   Mustermann\" <m@x.de>"   => [["Max   Mustermann", "m", "x.de"]],
            // quoted display name and address without angle brackets
            "\"John Doe\" john@x.com"         => [["John Doe", "john", "x.com"]],
        ];
        foreach ($cases as $list => $expected) {
            self::assertSame($expected, $this->to($list), $list);
        }
    }

    public function testEncodedDisplayNames(): void {
        self::assertSame([["Jürgen", "j", "x.de"]], $this->to("=?utf-8?Q?J=C3=BCrgen?= <j@x.de>"));
        // encoded words inside quotes are decoded too (common, although not allowed by RFC 2047)
        self::assertSame([["Jürgen", "j", "x.de"]], $this->to("\"=?utf-8?Q?J=C3=BCrgen?=\" <j@x.de>"));
        // the whitespace between two encoded words is dropped, the whitespace in front of plain text is kept
        self::assertSame([["Jürgen Müller", "j", "x.de"]], $this->to("=?utf-8?Q?J=C3=BCrgen_?= =?utf-8?Q?M=C3=BCller?= <j@x.de>"));
        self::assertSame([["Jörg Muster", "j", "x.de"]], $this->to("=?ISO-8859-1?Q?J=F6rg?= Muster <j@x.de>"));
        // a base64 encoded display name containing a comma does not split the list
        self::assertSame([["Doe, John", "j", "x.de"]], $this->to("=?utf-8?B?" . base64_encode("Doe, John") . "?= <j@x.de>"));
        // charsets iconv does not know by name are converted via EncodingAliases
        self::assertSame([["김 현진", "from", "there.com"]], $this->to("=?ks_c_5601-1987?B?seggx/bB+A==?= <from@there.com>"));
    }

    public function testAddressHeadersAreMergedAndKeptRaw(): void {
        $header = new Header("To: a@b.c\r\nTo: \"X, Y\" <d@e.f>\r\nCc:\r\nBcc: <bare@x.org>\r\n", $this->config);

        self::assertSame(["a@b.c", "d@e.f"], array_map(function(Address $address) {
            return $address->mail;
        }, $header->get("to")->all()));
        // the raw value is kept unchanged (c-client rewrites it)
        self::assertSame("a@b.c, \"X, Y\" <d@e.f>", (string)$header->get("toaddress"));
        self::assertSame("<bare@x.org>", (string)$header->get("bccaddress"));
        self::assertSame([], $header->get("cc")->all());
        self::assertSame("", (string)$header->get("ccaddress"));
    }

    public function testReplyToAndSenderDefaultToFrom(): void {
        // like imap_rfc822_parse_headers(): replies go to "from" if there is no Reply-To header
        $header = new Header("From: Max <max@example.de>\r\nSubject: x", $this->config);

        self::assertSame("max@example.de", $header->get("reply_to")->first()->mail);
        self::assertSame("max@example.de", $header->get("sender")->first()->mail);
        self::assertSame("Max <max@example.de>", (string)$header->get("reply_toaddress"));
        self::assertSame("Max <max@example.de>", (string)$header->get("senderaddress"));
        self::assertFalse($header->has("spoofed"));

        // explicit headers are kept
        $header = new Header("From: max@example.de\r\nReply-To: list@example.de\r\nSender: bounce@example.de", $this->config);
        self::assertSame("list@example.de", $header->get("reply_to")->first()->mail);
        self::assertSame("bounce@example.de", $header->get("sender")->first()->mail);

        // no defaults without a valid from address
        $header = new Header("From: Name Only\r\nSubject: x", $this->config);
        self::assertFalse($header->has("reply_to"));
        self::assertFalse($header->has("sender"));
    }

    public function testNullSenderIsNoSpoofing(): void {
        $header = new Header("Return-Path: <>\r\nFrom: MAILER-DAEMON@example.com (Mail Delivery System)", $this->config);

        self::assertFalse($header->has("spoofed"));
        self::assertSame("", $header->get("return_path")->first()->mail);

        // a different, non-empty Return-Path still counts
        $header = new Header("Return-Path: <other@example.org>\r\nFrom: a@example.com", $this->config);
        self::assertTrue($header->get("spoofed")->first());
    }

    public function testStringAddressHeadersWithSeveralValues(): void {
        // Delivered-To is often added by every hop
        $header = new Header("Delivered-To: a@example.com\r\nDelivered-To: b@example.com\r\nEnvelope-To: c@example.com, d@example.com", $this->config);

        self::assertSame(["a@example.com", "b@example.com"], array_map(function(Address $address) {
            return $address->mail;
        }, $header->get("delivered_to")->all()));
        self::assertCount(2, $header->get("envelope_to")->all());
    }
}
