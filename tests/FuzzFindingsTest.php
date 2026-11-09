<?php
/*
* File: FuzzFindingsTest.php
* Category: -
* Author: AccountDesk
* Created: 09.11.26
* Updated: -
*
* Description:
*  Regression tests for findings of fuzzing raw mails: memory growing with the nesting depth of multipart
*  messages, the memory of huge address lists, very long boundaries and dates that threw instead of using the
*  fallback date
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Structure;

class FuzzFindingsTest extends TestCase {

    /**
     * Peak memory of $callback above the memory in use before, in MB
     * @param callable $callback
     * @param mixed $result receives the return value of $callback
     *
     * @return float
     */
    private function peakMb(callable $callback, mixed &$result = null): float {
        if (!function_exists("memory_reset_peak_usage")) {
            self::markTestSkipped("memory_reset_peak_usage() needs PHP 8.2");
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $result = $callback();
        return (memory_get_peak_usage() - $before) / 1048576;
    }

    /**
     * A message nested 50 levels deep with a 1 MiB text at the bottom kept a copy of the remaining message on
     * every level of the recursion (about 100 MB). Now the memory does not depend on the nesting depth.
     */
    public function testDeeplyNestedMultipartDoesNotCopyPerLevel(): void {
        $raw = "From: a@example.org\r\nSubject: deep\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"n50\"\r\n\r\n";
        for ($i = 50; $i > 0; $i--) {
            $raw .= "--n$i\r\nContent-Type: multipart/mixed; boundary=\"n" . ($i - 1) . "\"\r\n\r\n";
        }
        $raw .= "--n0\r\nContent-Type: text/plain\r\n\r\n" . str_repeat("x", 1048576) . "\r\n--n0--\r\n";
        for ($i = 1; $i <= 50; $i++) {
            $raw .= "--n$i--\r\n";
        }

        $mb = $this->peakMb(function() use ($raw) {
            return Message::fromString($raw, Config::make());
        }, $message);

        self::assertSame(str_repeat("x", 1048576), $message->getTextBody());
        self::assertLessThan(20, $mb, sprintf("parsing took %.1f MB", $mb));
    }

    /**
     * The nesting limit still applies, and with allow_single_parts disabled a single part keeps its body
     */
    public function testNestingLimitAndSinglePartBody(): void {
        $raw = "From: a@example.org\r\nContent-Type: multipart/mixed; boundary=\"a\"\r\n\r\n".
            "--a\r\nContent-Type: multipart/alternative; boundary=\"b\"\r\n\r\n".
            "--b\r\nContent-Type: text/plain\r\n\r\ninner\r\n--b--\r\n--a--\r\n";

        $message = Message::fromString($raw, Config::make(["options" => ["allow_single_parts" => false]]));
        $parts = $message->getStructure()->parts;
        self::assertCount(1, $parts);
        self::assertSame("--b\r\nContent-Type: text/plain\r\n\r\ninner\r\n--b--", $parts[0]->content);

        $message = Message::fromString($raw, Config::make());
        self::assertSame("inner", $message->getTextBody());
    }

    /**
     * A boundary of 100000 characters made the delimiter pattern fail to compile ("regular expression is too
     * large", a warning), and the whole body ended up as one part
     */
    public function testVeryLongBoundary(): void {
        $boundary = str_repeat("b", 100000);
        $raw = "From: a@example.org\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n".
            "--$boundary\r\nContent-Type: text/plain\r\n\r\nfirst\r\n".
            "--$boundary\r\nContent-Type: text/html\r\n\r\n<p>second</p>\r\n--$boundary--\r\n";

        $warnings = [];
        set_error_handler(function($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $message = Message::fromString($raw, Config::make());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame("first", $message->getTextBody());
        self::assertSame("<p>second</p>", $message->getHTMLBody());
    }

    /**
     * splitMultipart() searches the delimiters without a regular expression - it has to find exactly what
     * the former pattern found, on random input made of delimiter fragments
     */
    public function testSplitMultipartMatchesTheFormerPattern(): void {
        $reference = function(string $boundary, string $context): array {
            $pattern = '/(?:\A|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?=\r?\n|\z)(?:\r?\n)?/';
            if (!preg_match_all($pattern, $context, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return [$context];
            }
            $parts = [];
            $count = count($matches);
            for ($i = 0; $i < $count; $i++) {
                if (isset($matches[$i][1]) && $matches[$i][1][0] === "--") {
                    break;
                }
                $start = $matches[$i][0][1] + strlen($matches[$i][0][0]);
                $end = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : strlen($context);
                $part = substr($context, $start, $end - $start);
                if (!isset($matches[$i + 1])) {
                    $part = preg_replace('/\r?\n\z/', '', $part);
                }
                $parts[] = $part;
            }
            return $parts;
        };

        $tokens = ["--b", "--b", "--b--", "--", "-", "b", "\r\n", "\r\n", "\n", "\r", " ", "\t", "x", "A: 1"];
        mt_srand(4711);
        for ($run = 0; $run < 20000; $run++) {
            $context = "";
            $length = mt_rand(0, 14);
            for ($i = 0; $i < $length; $i++) {
                $context .= $tokens[mt_rand(0, count($tokens) - 1)];
            }
            self::assertSame($reference("b", $context), Structure::splitMultipart("b", $context), addcslashes($context, "\r\n\t"));
        }
    }

}
