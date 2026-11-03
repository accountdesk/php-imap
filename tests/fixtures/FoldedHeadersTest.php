<?php
/*
* File: FoldedHeadersTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

use Webklex\PHPIMAP\IMAP;

/**
 * Class FoldedHeadersTest
 *
 * @package Tests\fixtures
 */
class FoldedHeadersTest extends FixtureTestCase {

    /**
     * Test the fixture folded_headers.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("folded_headers.eml");

        // folded header lines are unfolded
        self::assertSame("This is a very long subject line that has been folded over two lines", (string)$message->subject);
        self::assertSame(["ref1@example.com", "ref2@example.com", "ref3@example.com"], $message->references->all());
        self::assertSame("ref3@example.com", (string)$message->in_reply_to);
        self::assertSame("folded@example.com", (string)$message->message_id);

        // empty header values are kept
        self::assertTrue($message->getHeader()->has("x-empty"));
        self::assertSame("", (string)$message->x_empty);

        // "2 (High)" is mapped to the priority constant
        self::assertSame(IMAP::MESSAGE_PRIORITY_HIGH, (int)$message->priority->first());
        self::assertSame("2 (High)", (string)$message->x_priority);

        // content type parameters are extracted
        self::assertSame("utf-8", (string)$message->charset);
        self::assertSame("flowed", (string)$message->format);

        // 8bit bodies are passed through unchanged
        self::assertSame("Body with 8bit content: äöü €", $message->getTextBody());
        self::assertFalse($message->spoofed->first());
        self::assertEquals("2024-01-06 17:45:30", $message->date->first()->setTimezone('UTC')->format("Y-m-d H:i:s"));
    }

    /**
     * Repeated headers should be kept as separate values
     *
     * @return void
     */
    public function testRepeatedHeaders() : void {
        $message = $this->getFixture("folded_headers.eml");

        self::assertSame([
            "from mx1.example.com by mx2.example.com; Fri, 05 Jan 2024 09:15:00 -0500",
            "from client.example.com by mx1.example.com; Fri, 05 Jan 2024 09:14:59 -0500",
        ], $message->received->all());
        self::assertSame(["first", "second"], $message->x_custom->all());
    }
}
