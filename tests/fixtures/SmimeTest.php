<?php
/*
* File: SmimeTest.php
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
 * Class SmimeTest
 *
 * S/MIME signed (multipart/signed with a preamble, nested alternative, detached signature) and S/MIME
 * encrypted (single application/pkcs7-mime part) messages.
 *
 * @package Tests\fixtures
 */
class SmimeTest extends FixtureTestCase {

    /**
     * Test the fixture smime_signed.eml
     *
     * @return void
     */
    public function testSigned() : void {
        $message = $this->getFixture("smime_signed.eml");

        self::assertSame("Signed", (string)$message->subject);
        self::assertSame("application/pkcs7-signature", (string)$message->protocol);
        self::assertSame("sha-256", (string)$message->micalg);
        self::assertSame("Signed text", $message->getTextBody());
        self::assertSame("<p>Signed text</p>", $message->getHTMLBody());

        self::assertCount(1, $message->getAttachments());
        $signature = $message->getAttachments()->first();
        self::assertSame("smime.p7s", $signature->name);
        self::assertSame("application", $signature->type);
        self::assertSame("application/pkcs7-signature", $signature->content_type);
        self::assertSame("S/MIME Cryptographic Signature", $signature->description);
        self::assertSame(300, strlen($signature->content));
        $expected = "";
        for ($i = 0; $i < 300; $i++) {
            $expected .= chr(($i * 7) % 256);
        }
        self::assertSame($expected, $signature->content);
        // text, html, signature - the preamble is no part
        self::assertSame([0, 1, 2], array_map(function($part) {
            return $part->part_number;
        }, $message->getStructure()->parts));
    }

    /**
     * Test the fixture smime_encrypted.eml
     *
     * @return void
     */
    public function testEncrypted() : void {
        $message = $this->getFixture("smime_encrypted.eml");

        self::assertSame("Encrypted", (string)$message->subject);
        self::assertSame("enveloped-data", (string)$message->smime_type);
        self::assertFalse($message->hasTextBody());
        self::assertFalse($message->hasHTMLBody());

        self::assertCount(1, $message->getAttachments());
        $attachment = $message->getAttachments()->first();
        self::assertSame("smime.p7m", $attachment->name);
        self::assertSame("application/pkcs7-mime", $attachment->content_type);
        self::assertSame("application", $attachment->type);
        self::assertSame("p7m", $attachment->getExtension());
        $expected = "";
        for ($i = 0; $i < 500; $i++) {
            $expected .= chr(($i * 13 + 5) % 256);
        }
        self::assertSame($expected, $attachment->content);
    }
}
