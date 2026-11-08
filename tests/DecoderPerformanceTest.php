<?php
/*
* File: DecoderPerformanceTest.php
* Category: -
* Author: AccountDesk
* Created: 08.11.26
* Updated: -
*
* Description:
*  Regression tests for header decoding taking seconds on hostile input (found by fuzzing raw mails)
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;
use Webklex\PHPIMAP\Decoder\MessageDecoder;
use Webklex\PHPIMAP\EncodingAliases;
use Webklex\PHPIMAP\Message;

class DecoderPerformanceTest extends TestCase {

    /**
     * Generous upper bound: the fixed code needs a few milliseconds, the old one several seconds
     */
    private const MAX_SECONDS = 1.5;

    /**
     * convert() is called once per encoded-word of a header. With a known charset it must not guess the
     * encoding of the string (mb_detect_encoding over all charsets, eagerly evaluated twice per call before).
     */
    public function testConvertWithKnownEncodingsIsCheap(): void {
        $start = microtime(true);
        for ($i = 0; $i < 30000; $i++) {
            $result = EncodingAliases::convert("\xf0\xd2\xc9\xd7\xc5\xd4", "KOI8-R", "UTF-8");
        }
        $seconds = microtime(true) - $start;

        self::assertSame("Привет", $result);
        self::assertLessThan(self::MAX_SECONDS, $seconds, sprintf("30000 conversions took %.2f s", $seconds));
    }

    /**
     * The encoding list for mb_detect_encoding() is built once instead of quadratically on every call
     */
    public function testDetectEncodingIsCheap(): void {
        $start = microtime(true);
        for ($i = 0; $i < 60000; $i++) {
            $result = EncodingAliases::detectEncoding("plain ascii " . $i);
        }
        $seconds = microtime(true) - $start;

        self::assertSame("ASCII", $result);
        self::assertSame(array_values(array_unique(EncodingAliases::getEncodings())), EncodingAliases::getEncodings());
        self::assertLessThan(self::MAX_SECONDS, $seconds, sprintf("60000 detections took %.2f s", $seconds));
    }

    /**
     * Fuzzing reproducer: 20000 repeated address headers with an encoded-word plus a subject made of
     * 10000 encoded-words. Parsing took several seconds before the fix (> 3 s on the fuzzing CI runner).
     */
    public function testMessageWithThousandsOfEncodedWordsParsesQuickly(): void {
        new ClientManager();
        $raw = "From: a@example.com\r\nTo: =?UTF-8?B?U3VwcG9ydCBUZWFt?= <support@example.com>\r\n"
            . str_repeat("Cc: =?KOI8-R?B?8NLJ18XU?= <ru@example.ru>\r\n", 20000)
            . "Subject: " . implode(" ", array_fill(0, 10000, "=?KOI8-R?B?8NLJ18XU?=")) . "\r\n"
            . "Date: Mon, 2 Nov 2026 10:00:00 +0100\r\nMessage-ID: <fuzz@example.com>\r\n\r\nHello\r\n";

        $start = microtime(true);
        $message = Message::fromString($raw);
        $subject = (string)$message->getSubject();
        $seconds = microtime(true) - $start;

        self::assertNotSame("", $subject);
        self::assertLessThan(self::MAX_SECONDS, $seconds, sprintf("parsing took %.2f s", $seconds));
    }

    /**
     * getEncoding() accepts a string; property_exists() would treat it as a class name and run the autoloader
     */
    public function testGetEncodingDoesNotAutoloadTheValue(): void {
        $value = "Fuzz\\HeaderValue" . str_repeat("x", 1000);
        $autoloaded = [];
        $autoloader = function(string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($autoloader);
        try {
            (new HeaderDecoder())->getEncoding($value);
            (new MessageDecoder())->getEncoding($value);
        } finally {
            spl_autoload_unregister($autoloader);
        }

        self::assertNotContains($value, $autoloaded);
    }
}
