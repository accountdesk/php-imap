<?php
/*
* File: HebrewIso88598ITest.php
* Category: -
* Author: -
* Created: 25.10.26 00:30
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

/**
 * Class HebrewIso88598ITest
 *
 * Hebrew mail in "iso-8859-8-i" (logical text direction), which iconv does not know by this name, and
 * windows-1255.
 *
 * @package Tests\fixtures
 */
class HebrewIso88598ITest extends FixtureTestCase {

    /**
     * Test the fixture hebrew_iso_8859_8_i.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("hebrew_iso_8859_8_i.eml");

        self::assertSame("שלום עולם", (string)$message->subject);
        self::assertSame("דני", $message->from->first()->personal);
        // the body used to be empty: iconv returned false for "ISO-8859-8-I"
        self::assertSame("שלום, מה שלומך?", $message->getTextBody());
        self::assertSame("<p dir=\"rtl\">שלום</p>\n", $message->getHTMLBody());
    }
}
