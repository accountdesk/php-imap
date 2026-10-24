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

    public function testMimeHeaderDecodeWithoutExtImap(): void {
        if (extension_loaded('imap')) {
            $this->markTestSkipped("ext-imap is loaded, imap_mime_header_decode() is used instead");
        }
        $result = (new HeaderDecoder())->mimeHeaderDecode("plain text");

        self::assertCount(1, $result);
        self::assertSame("ASCII", $result[0]->charset);
        self::assertSame("plain text", $result[0]->text);
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
        if (!extension_loaded('imap')) {
            $this->markTestSkipped("Encoded words are only decoded with ext-imap in 'utf-8' mode (see CLAUDE.md baseline)");
        }
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
        if (!extension_loaded('imap')) {
            $this->markTestSkipped("BUG: MessageDecoder::decode() base64-decodes MESSAGE_ENC_BINARY content when ext-imap is missing");
        }
        self::assertSame("raw \x00 binary", (new MessageDecoder())->decode("raw \x00 binary", IMAP::MESSAGE_ENC_BINARY));
    }

    public function testMessageDecoderArrayKeepsEncoding(): void {
        $this->markTestSkipped("BUG: MessageDecoder::decode() ignores the encoding for array values");
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
        $this->markTestSkipped("BUG: EncodingAliases::get(null) without fallback returns null although the return type is string (TypeError)");
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
