<?php
/*
* File: LongFoldedHeadersTest.php
* Category: -
* Author: -
* Created: 24.10.26 22:00
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

/**
 * Class LongFoldedHeadersTest
 *
 * Mailing list message with a subject of six encoded words on six lines, 30 references on 30 lines and a
 * header of about 5000 characters (unfolded and folded).
 *
 * @package Tests\fixtures
 */
class LongFoldedHeadersTest extends FixtureTestCase {

    /**
     * Test the fixture long_folded_headers.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("long_folded_headers.eml");

        self::assertSame("Sehr lange Betreffzeile mit Ümlauten, die über mehrere encoded words und Zeilen gefaltet wurde – Teil 2", (string)$message->subject);

        $references = $message->references->all();
        self::assertCount(30, $references);
        self::assertSame("ref00.xxxxxxxxxxxxxxxxxxxx@lists.example.org", $references[0]);
        self::assertSame("ref29.xxxxxxxxxxxxxxxxxxxx@lists.example.org", $references[29]);
        self::assertSame("ref29.xxxxxxxxxxxxxxxxxxxx@lists.example.org", (string)$message->in_reply_to);

        $tokens = [];
        for ($i = 0; $i < 500; $i++) {
            $tokens[] = sprintf("token%04d", $i);
        }
        self::assertSame(implode(" ", $tokens), (string)$message->x_long_unfolded);
        // folded at arbitrary positions: unfolding inserts no characters, but the leading whitespace of each
        // continuation line is kept (RFC 5322 2.2.3)
        $folded = (string)$message->x_long_folded;
        self::assertSame(str_replace(" ", "", implode(" ", $tokens)), str_replace(" ", "", $folded));
        self::assertStringStartsWith("token0000 token0001", $folded);

        self::assertSame("Body", $message->getTextBody());
    }
}
