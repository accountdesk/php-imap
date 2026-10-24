<?php
/*
* File: BinaryTransferEncodingTest.php
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
 * Class BinaryTransferEncodingTest
 *
 * @package Tests\fixtures
 */
class BinaryTransferEncodingTest extends FixtureTestCase {

    /**
     * Test the fixture binary_transfer_encoding.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("binary_transfer_encoding.eml");

        self::assertSame("binary", (string)$message->content_transfer_encoding);
        self::assertSame("Hello binary world", $message->getTextBody());
    }
}
