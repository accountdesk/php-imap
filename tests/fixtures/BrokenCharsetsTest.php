<?php
/*
* File: BrokenCharsetsTest.php
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
 * Class BrokenCharsetsTest
 *
 * Raw 8bit (Windows-1252) bytes in headers, "unknown-8bit", a charset alias ("utf8"), Windows-1252
 * characters in quoted-printable and an unknown charset name.
 *
 * @package Tests\fixtures
 */
class BrokenCharsetsTest extends FixtureTestCase {

    /**
     * Test the fixture broken_charsets.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("broken_charsets.eml");

        // undeclared 8bit bytes in headers (not valid UTF-8) are read as Windows-1252
        self::assertSame("Ihre Bestellung über 5 €", (string)$message->subject);
        self::assertSame("Grüße Versand", $message->from->first()->personal);
        self::assertSame("versand@example.de", $message->from->first()->mail);

        // unknown-8bit falls back to ISO-8859-2 (same as ISO-8859-1 for these characters), windows-1252
        // quoted-printable, an unknown charset is passed through
        self::assertSame("Grüße\nPreis: 5 € “netto”\nplain ascii", $message->getTextBody());
        // "utf8" is an alias of UTF-8
        self::assertSame("<p>Ärger</p>", $message->getHTMLBody());
        self::assertCount(0, $message->getAttachments());
    }
}
