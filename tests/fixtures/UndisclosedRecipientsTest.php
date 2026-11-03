<?php
/*
* File: UndisclosedRecipientsTest.php
* Category: -
* Author: M.Goldenbaum
* Created: 09.03.23 02:24
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

/**
 * Class UndisclosedRecipientsTest
 *
 * @package Tests\fixtures
 */
class UndisclosedRecipientsTest extends FixtureTestCase {

    /**
     * Test the fixture undisclosed_recipients.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("undisclosed_recipients.eml");

        self::assertEquals("test", $message->subject);
        self::assertEquals("Hi!", $message->getTextBody());
        self::assertFalse($message->hasHTMLBody());
        self::assertEquals("2017-09-27 10:48:51", $message->date->first()->setTimezone('UTC')->format("Y-m-d H:i:s"));
        self::assertEquals("from@there.com", $message->from);
        // '"Undisclosed Recipients" <>' is a display name with an empty address (c-client returned the display
        // name as mailbox plus an "UNEXPECTED_DATA_AFTER_ADDRESS" placeholder)
        $to = $message->to->all();
        self::assertCount(1, $to);
        self::assertSame("Undisclosed Recipients", $to[0]->personal);
        self::assertSame("", $to[0]->mailbox);
        self::assertSame("", $to[0]->host);
        self::assertSame("", $to[0]->mail);
    }
}