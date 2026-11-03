<?php
/*
* File: DecoderTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the header / message / attachment decoders and the encoding aliases.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Decoder\AttachmentDecoder;
use Webklex\PHPIMAP\Decoder\Decoder;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;
use Webklex\PHPIMAP\Decoder\MessageDecoder;
use Webklex\PHPIMAP\EncodingAliases;
use Webklex\PHPIMAP\IMAP;

class DecoderTest extends TestCase {

    public function testDefaultOptions(): void {
        $decoder = new HeaderDecoder();
        self::assertSame(["header" => "utf-8", "message" => "utf-8", "attachment" => "utf-8"], $decoder->getOptions());
        self::assertSame("UTF-8", $decoder->getFallbackEncoding());

        $decoder = new MessageDecoder(["message" => "iconv"], "ISO-8859-1");
        self::assertSame("iconv", $decoder->getOptions()["message"]);
        self::assertSame("utf-8", $decoder->getOptions()["header"]);
        self::assertSame("ISO-8859-1", $decoder->getFallbackEncoding());

        self::assertSame(["foo" => "bar"], $decoder->setOptions(["foo" => "bar"])->getOptions());
        self::assertSame("UTF-8", $decoder->setFallbackEncoding("UTF-8")->getFallbackEncoding());
        self::assertInstanceOf(MessageDecoder::class, new AttachmentDecoder());
    }

    public function testBaseDecoderPassesValuesThrough(): void {
        $decoder = new class extends Decoder {
            public function getEncoding(object|string $structure): string {
                return "UTF-8";
            }
        };

        self::assertSame("=?UTF-8?Q?x?=", $decoder->decode("=?UTF-8?Q?x?="));
        self::assertNull($decoder->decode(null));
        self::assertSame(["a"], $decoder->decode(["a"]));
        self::assertSame("Grüße", $decoder->convertEncoding("Gr\xFC\xDFe", "ISO-8859-1"));
        self::assertSame("abc", $decoder->convertEncoding("abc", "utf8", "UTF-8"));
    }

    public function testMimeHeaderDecodePlainText(): void {
        // same result as imap_mime_header_decode(): plain text has the charset "default"
        $result = (new HeaderDecoder())->mimeHeaderDecode("plain text");

        self::assertCount(1, $result);
        self::assertSame("default", $result[0]->charset);
        self::assertSame("plain text", $result[0]->text);
    }

    /**
     * The pure PHP implementation used without ext-imap must split and decode like imap_mime_header_decode()
     */
    public function testDecodeMimeHeaderMatchesImapMimeHeaderDecode(): void {
        $cases = [
            "plain text"                                     => [["default", "plain text"]],
            "=?UTF-8?B?w6TDtsO8?="                           => [["UTF-8", "äöü"]],
            "=?ISO-8859-1?Q?J=F6rg?= Muster"                 => [["ISO-8859-1", "J\xF6rg"], ["default", " Muster"]],
            // whitespace between adjacent encoded-words is dropped, other whitespace is kept
            "Re: =?utf-8?Q?a?= =?utf-8?Q?b?=  tail"          => [["default", "Re: "], ["utf-8", "a"], ["utf-8", "b"], ["default", "  tail"]],
            "=?utf-8?Q?a?=\r\n =?utf-8?Q?b?="                => [["utf-8", "a"], ["utf-8", "b"]],
            "=?utf-8?Q?a?=\t=?utf-8?Q?b?="                    => [["utf-8", "a"], ["utf-8", "b"]],
            "=?utf-8?Q?a?==?utf-8?Q?b?="                     => [["utf-8", "a"], ["utf-8", "b"]],
            "=?utf-8?Q?a?= x =?utf-8?Q?b?="                  => [["utf-8", "a"], ["default", " x "], ["utf-8", "b"]],
            // "_" is a space in Q encoding, lower case encodings are valid
            "=?utf-8?Q?a_b?="                                => [["utf-8", "a b"]],
            "=?utf-8?q?=C3=A4?="                             => [["utf-8", "ä"]],
            "=?utf-8?b?w6Q?="                                => [["utf-8", "ä"]],
            // a space inside a Q word (broken mailers) is accepted like c-client does
            "=?utf-8?Q?hello world?="                        => [["utf-8", "hello world"]],
            "=?utf-8?Q?=ZZ?="                                => [["utf-8", "=ZZ"]],
            "=?utf-8?Q??="                                   => [["utf-8", ""]],
            // unterminated words and words inside other text
            "=?utf-8?Q?abc"                                  => [["default", "=?utf-8?Q?abc"]],
            "a =?utf-8?Q?b?=c"                               => [["default", "a "], ["utf-8", "b"], ["default", "c"]],
            "(=?utf-8?Q?a?=)"                                => [["default", "("], ["utf-8", "a"], ["default", ")"]],
            // the bytes are not converted: charsets iconv does not know are converted later via EncodingAliases
            "=?ks_c_5601-1987?B?seggx/bB+A==?="              => [["ks_c_5601-1987", "\xB1\xE8 \xC7\xF6\xC1\xF8"]],
            "Grüße"                                          => [["default", "Grüße"]],
            ""                                               => [],
            "   "                                            => [["default", "   "]],
        ];
        foreach ($cases as $input => $expected) {
            $actual = array_map(function($part) { return [$part->charset, $part->text]; }, Decoder::decodeMimeHeader((string)$input));
            self::assertSame($expected, $actual, $input);
            if (extension_loaded('imap')) {
                $imap = array_map(function($part) { return [$part->charset, $part->text]; }, \imap_mime_header_decode((string)$input));
                self::assertSame($imap, $actual, "imap_mime_header_decode(): " . $input);
            }
        }
    }

    public function testDecodeMimeHeaderKeepsUndecodableWords(): void {
        // c-client decodes these anyway (or fails completely), the pure implementation keeps them as text
        self::assertSame([["default", "=?utf-8?X?abc?="]], array_map(function($part) {
            return [$part->charset, $part->text];
        }, Decoder::decodeMimeHeader("=?utf-8?X?abc?=")));
        self::assertSame([["default", "=?utf-8?B?!!!?= "], ["utf-8", "ok"]], array_map(function($part) {
            return [$part->charset, $part->text];
        }, Decoder::decodeMimeHeader("=?utf-8?B?!!!?= =?utf-8?Q?ok?=")));
        // the RFC 2231 language suffix is no part of the charset
        self::assertSame("utf-8", Decoder::decodeMimeHeader("=?utf-8*de?Q?a?=")[0]->charset);
    }

    public function testHeaderDecoderReadsUndeclared8bitAsWindows1252(): void {
        $decoder = new HeaderDecoder();

        // raw Windows-1252 / ISO-8859-1 bytes are no valid UTF-8
        self::assertSame("Grüße für 5 €", $decoder->decode("Gr\xFC\xDFe f\xFCr 5 \x80"));
        // raw UTF-8 (RFC 6532) is kept
        self::assertSame("Grüße", $decoder->decode("Grüße"));
        // mixed with an encoded word
        self::assertSame("Re: Grüße – Köln", $decoder->decode("Re: Gr\xFC\xDFe =?utf-8?Q?=E2=80=93?= K\xF6ln"));
        // invalid UTF-8 inside a UTF-8 encoded word is not touched (#420)
        self::assertSame("a\xFFb", $decoder->decode("=?utf-8?B?" . base64_encode("a\xFFb") . "?="));
    }

    public function testHeaderDecoderDecodesUnknownIconvCharsets(): void {
        // iconv does not know ks_c_5601-1987, EncodingAliases maps it to EUC-KR
        self::assertSame("김 현진", (new HeaderDecoder())->decode("=?ks_c_5601-1987?B?seggx/bB+A==?="));
    }

    public function testHeaderDecoderPlainValues(): void {
        $decoder = new HeaderDecoder();

        self::assertSame("Hello World", $decoder->decode("Hello World"));
        self::assertSame("Grüße", $decoder->decode("Grüße"));
        self::assertNull($decoder->decode(null));
        self::assertSame(["a", "b"], $decoder->decode(["a", "b"]));
        self::assertSame(["a", ["b"]], $decoder->decode(["a", ["b"]]));
    }

    public function testHeaderDecoderEncodedWords(): void {
        $decoder = new HeaderDecoder();

        self::assertSame("äöü", $decoder->decode("=?UTF-8?B?w6TDtsO8?="));
        self::assertSame("Jörg Muster", $decoder->decode("=?ISO-8859-1?Q?J=F6rg?= Muster"));
    }

    public function testHeaderDecoderIconvMode(): void {
        $decoder = new HeaderDecoder(["header" => "iconv"]);

        self::assertSame("äöü", $decoder->decode("=?UTF-8?B?w6TDtsO8?="));
        self::assertSame("Jörg Muster", $decoder->decode("=?ISO-8859-1?Q?J=F6rg?= Muster"));
        self::assertSame("Plain", $decoder->decode("Plain"));
    }

    public function testHeaderDecoderMimeheaderMode(): void {
        $decoder = new HeaderDecoder(["header" => "mimeheader"]);

        self::assertSame("Grüße", $decoder->decode("=?utf-8?Q?Gr=C3=BC=C3=9Fe?="));
        // only UTF-8 encoded words are decoded in this mode
        self::assertSame("Plain", $decoder->decode("Plain"));
    }

    public function testHeaderDecoderGetEncoding(): void {
        $decoder = new HeaderDecoder();

        self::assertSame("ISO-8859-1", $decoder->getEncoding((object)["parameters" => [
            (object)["attribute" => "name", "value" => "file.txt"],
            (object)["attribute" => "CHARSET", "value" => "latin1"],
        ]]));
        self::assertSame("us-ascii", $decoder->getEncoding((object)["parameters" => [(object)["attribute" => "charset", "value" => "default"]]]));
        self::assertSame("UTF-8", $decoder->getEncoding((object)["parameters" => []]));
        self::assertSame("windows-1252", $decoder->getEncoding((object)["charset" => "cp1252"]));
        self::assertSame("us-ascii", $decoder->getEncoding((object)["charset" => "default"]));
        // unknown charsets use the fallback encoding
        self::assertSame("UTF-8", $decoder->getEncoding((object)["charset" => "x-unknown"]));
        self::assertSame("ISO-8859-1", $decoder->setFallbackEncoding("ISO-8859-1")->getEncoding((object)["charset" => "x-unknown"]));
        self::assertSame("UTF-8", $decoder->getEncoding("Grüße"));
        self::assertSame("ASCII", $decoder->getEncoding("abc"));
        self::assertSame("ISO-8859-1", $decoder->getEncoding(new \stdClass()));
    }

    public function testMessageDecoderTransferEncodings(): void {
        $decoder = new MessageDecoder();

        self::assertSame("x\0y", $decoder->decode(base64_encode("x\0y"), IMAP::MESSAGE_ENC_BASE64));
        self::assertSame("a=b äöü", $decoder->decode("a=3Db =C3=A4=C3=B6=C3=BC", IMAP::MESSAGE_ENC_QUOTED_PRINTABLE));
        self::assertSame("soft break", $decoder->decode("soft =\r\nbreak", IMAP::MESSAGE_ENC_QUOTED_PRINTABLE));
        self::assertSame("a=3Db", $decoder->decode("a=3Db", IMAP::MESSAGE_ENC_7BIT));
        self::assertSame("a=3Db", $decoder->decode("a=3Db", IMAP::MESSAGE_ENC_8BIT));
        self::assertSame("a=3Db", $decoder->decode("a=3Db", IMAP::MESSAGE_ENC_OTHER));
        self::assertSame("a=3Db", $decoder->decode("a=3Db"));
        self::assertSame("a=3Db", $decoder->decode("a=3Db", 99));
    }

    public function testMessageDecoderBinaryEncoding(): void {
        self::assertSame("raw \x00 binary", (new MessageDecoder())->decode("raw \x00 binary", IMAP::MESSAGE_ENC_BINARY));
    }

    public function testMessageDecoderArrayKeepsEncoding(): void {
        $decoder = new MessageDecoder();

        self::assertSame(["x", "y"], $decoder->decode([base64_encode("x"), base64_encode("y")], IMAP::MESSAGE_ENC_BASE64));
    }

    public function testMessageDecoderGetEncoding(): void {
        $decoder = new MessageDecoder();

        self::assertSame("windows-1252", $decoder->getEncoding((object)["parameters" => [(object)["attribute" => "Charset", "value" => "x-cp1252"]]]));
        // unknown charsets fall back to ISO-8859-2
        self::assertSame("ISO-8859-2", $decoder->getEncoding((object)["parameters" => [(object)["attribute" => "charset", "value" => "x-unknown"]]]));
        self::assertSame("UTF-8", $decoder->getEncoding((object)["parameters" => [(object)["attribute" => "format", "value" => "flowed"]]]));
        self::assertSame("UTF-8", $decoder->getEncoding((object)["charset" => "UTF8"]));
        self::assertSame("ISO-8859-2", $decoder->getEncoding((object)["charset" => ""]));
        self::assertSame("UTF-8", $decoder->getEncoding("Grüße"));
        self::assertSame("ASCII", $decoder->getEncoding("abc"));
        self::assertSame("UTF-8", $decoder->getEncoding(new \stdClass()));
    }

    public function testMessageDecoderConvertEncoding(): void {
        $decoder = new MessageDecoder();

        self::assertSame("Grüße", $decoder->convertEncoding("Gr\xFC\xDFe", "ISO-8859-1"));
        self::assertSame("Grüße", $decoder->convertEncoding("Gr\xFC\xDFe", "latin1", "utf8"));
        self::assertSame("Gr\xFC\xDFe", $decoder->convertEncoding("Grüße", "UTF-8", "ISO-8859-1"));
        self::assertSame("Preis: 5 €", $decoder->convertEncoding("Preis: 5 \x80", "windows-1252"));
        self::assertSame("abc", $decoder->convertEncoding("abc", "utf8", "UTF-8"));
        // us-ascii is a subset of UTF-8 and returned unchanged
        self::assertSame("a\xFFb", $decoder->convertEncoding("a\xFFb", "ascii"));
        // UTF-7 is converted by mbstring
        self::assertSame("Hi Mom -☺-!", $decoder->convertEncoding("Hi Mom -+Jjo--!", "UTF-7"));
        // characters which can't be represented are dropped
        self::assertSame("ab", $decoder->convertEncoding("a\xFFb", "UTF-8", "ISO-8859-1"));
    }

    public function testConversionNeverLosesTheString(): void {
        $decoder = new MessageDecoder();

        // iconv does not know the -I / -E variants of ISO-8859-8 / ISO-8859-6 (Hebrew / Arabic mail)
        self::assertSame("שלום", $decoder->convertEncoding("\xF9\xEC\xE5\xED", "iso-8859-8-i"));
        self::assertSame("שלום", EncodingAliases::convert("\xF9\xEC\xE5\xED", "ISO-8859-8-E"));
        // undefined bytes (0x81 in windows-1252) are dropped instead of failing the whole conversion
        self::assertSame("Preis € x", EncodingAliases::convert("Preis \x80\x81 x", "windows-1252"));
        self::assertSame("Preis € x", $decoder->convertEncoding("Preis \x80\x81 x", "windows-1252"));
        // encodings neither iconv nor mbstring know: the string is kept
        self::assertSame("abc", EncodingAliases::convertString("abc", "x-totally-unknown"));
        // mbstring is used where iconv fails
        self::assertSame("中", EncodingAliases::convertString("~{VP~}", "HZ"));
        // non-string values are passed through
        self::assertNull($decoder->convertEncoding(null, "ISO-8859-1"));
    }

    public function testStaticHelpers(): void {
        self::assertTrue(Decoder::isUTF8("=?UTF-8?Q?x?="));
        self::assertTrue(Decoder::isUTF8("=?utf-8?B?eA==?="));
        self::assertFalse(Decoder::isUTF8("=?ISO-8859-1?Q?x?="));
        self::assertFalse(Decoder::isUTF8("plain"));

        self::assertTrue(Decoder::notDecoded("=?x?Q?a?=", "=?x?Q?a?="));
        self::assertTrue(Decoder::notDecoded("Re: =?x?Q?a?=", "=?x?Q?a?="));
        self::assertFalse(Decoder::notDecoded("=?x?Q?a?=", "a"));
        self::assertFalse(Decoder::notDecoded("plain", "plain"));
    }

    public function testEncodingAliasesGet(): void {
        self::assertSame("ISO-8859-1", EncodingAliases::get("LATIN1"));
        self::assertSame("windows-1252", EncodingAliases::get("cp1252"));
        self::assertSame("gbk", EncodingAliases::get("x-gbk"));
        self::assertSame("UTF-8", EncodingAliases::get("utf8"));
        self::assertSame("x-unknown", EncodingAliases::get("x-unknown"));
        self::assertSame("fallback", EncodingAliases::get("x-unknown", "fallback"));
        self::assertSame("UTF-8", EncodingAliases::get(null, "UTF-8"));
    }

    public function testEncodingAliasesGetNull(): void {
        self::assertSame("", EncodingAliases::get(null));
    }

    public function testEncodingAliasesHelpers(): void {
        self::assertTrue(EncodingAliases::has("Windows-1252"));
        self::assertTrue(EncodingAliases::has("UTF-8"));
        self::assertFalse(EncodingAliases::has("x-unknown"));

        self::assertTrue(EncodingAliases::isUtf7("UTF-7"));
        self::assertTrue(EncodingAliases::isUtf7("utf7-imap"));
        self::assertFalse(EncodingAliases::isUtf7("UTF-8"));

        $encodings = EncodingAliases::getEncodings();
        self::assertContains("UTF-8", $encodings);
        self::assertContains("ISO-8859-1", $encodings);
        self::assertSame(count($encodings), count(array_unique($encodings)));

        self::assertSame("ASCII", EncodingAliases::detectEncoding("abc"));
        self::assertSame("UTF-8", EncodingAliases::detectEncoding("Grüße"));
    }

    public function testEncodingAliasesConvert(): void {
        self::assertSame("Grüße", EncodingAliases::convert("Gr\xFC\xDFe", "latin1", "utf-8"));
        self::assertSame("Grüße", EncodingAliases::convert("Grüße", "utf-8", "utf8"));
        self::assertSame("abc", EncodingAliases::convert("abc", "us-ascii", "UTF-8"));
        self::assertSame("Hi Mom -☺-!", EncodingAliases::convert("Hi Mom -+Jjo--!", "utf-7", "UTF-8"));
        $utf7 = EncodingAliases::convert("Hi Mom -☺-!", "UTF-8", "UTF-7");
        self::assertStringNotContainsString("☺", $utf7);
        self::assertSame("Hi Mom -☺-!", EncodingAliases::convert($utf7, "UTF-7", "UTF-8"));
    }
}
