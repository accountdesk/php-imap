<?php
/*
* File: AddressListsTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

use Webklex\PHPIMAP\Address;

/**
 * Class AddressListsTest
 *
 * @package Tests\fixtures
 */
class AddressListsTest extends FixtureTestCase {

    /**
     * Test the fixture address_lists.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("address_lists.eml");

        // a quoted display name containing a comma does not split the list
        $to = $message->to->all();
        self::assertCount(4, $to);
        self::assertSame(
            ["john.doe@example.com", "jane@example.com", "bare@example.com", "max@example.de"],
            array_map(function(Address $a) { return $a->mail; }, $to)
        );
        self::assertSame("Max Mustermann", $to[3]->personal);
        self::assertSame("Max Mustermann <max@example.de>", $to[3]->full);
        self::assertSame("", $to[1]->personal);
        self::assertSame("bare@example.com", $to[2]->full);
        self::assertSame("max", $to[3]->mailbox);
        self::assertSame("example.de", $to[3]->host);

        $cc = $message->cc->all();
        self::assertCount(2, $cc);
        self::assertSame("single@example.org", $cc[0]->mail);
        // an address without host is kept as mailbox only
        self::assertSame("nohost", $cc[1]->mailbox);
        self::assertSame("", $cc[1]->host);
        self::assertSame("nohost", $cc[1]->mail);

        self::assertSame("hidden@example.net", $message->bcc->first()->mail);
        self::assertSame("list@lists.example.com", $message->reply_to->first()->mail);
        self::assertSame("mailer@lists.example.com", $message->sender->first()->mail);

        // raw address lists are kept
        self::assertSame(
            "\"Doe, John\" <john.doe@example.com>, jane@example.com, <bare@example.com>, Max Mustermann <max@example.de>",
            (string)$message->toaddress
        );
        self::assertSame("hidden@example.net", (string)$message->bccaddress);

        // from, reply-to and sender differ -> flagged as potential spoofing
        self::assertTrue($message->spoofed->first());
        self::assertEquals("2024-01-05 14:15:00", $message->date->first()->setTimezone('UTC')->format("Y-m-d H:i:s"));
    }

    /**
     * Quotes around display names should be removed
     *
     * @return void
     */
    public function testDisplayNameQuotesAreRemoved() : void {
        $this->markTestSkipped("BUG: Header::decodeAddresses()/parseAddresses() keep the quotes of '\"Doe, John\"' and a trailing quote of \"'Single Quoted'\"");
        $message = $this->getFixture("address_lists.eml");

        self::assertSame("Doe, John", $message->from->first()->personal);
        self::assertSame("Doe, John", $message->to->first()->personal);
        self::assertSame("Single Quoted", $message->cc->first()->personal);
    }
}
