<?php
/*
* File: CharsetVariantsTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

/**
 * Class CharsetVariantsTest
 *
 * @package Tests\fixtures
 */
class CharsetVariantsTest extends FixtureTestCase {

    /**
     * Test the fixture charset_variants.eml (ISO-8859-1 quoted-printable and windows-1252 base64 bodies)
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("charset_variants.eml");

        self::assertEquals("Charset variants", $message->subject);
        self::assertSame("Grüße aus Köln, äöüÄÖÜß and a soft line break.", $message->getTextBody());
        self::assertSame("<p>Preis: 5 € – „Zitat“</p>", $message->getHTMLBody());
        self::assertSame(0, $message->getAttachments()->count());
        self::assertSame("joerg@example.com", $message->from->first()->mail);
        self::assertEquals("2024-01-03 08:30:00", $message->date->first()->format("Y-m-d H:i:s"));
    }

    /**
     * Test the encoded word inside the from header
     *
     * @return void
     */
    public function testEncodedPersonalName() : void {
        if (!extension_loaded('imap')) {
            $this->markTestSkipped("Encoded words in headers are only decoded with ext-imap (see CLAUDE.md baseline)");
        }
        $message = $this->getFixture("charset_variants.eml");

        self::assertSame("Jörg", $message->from->first()->personal);
    }
}
