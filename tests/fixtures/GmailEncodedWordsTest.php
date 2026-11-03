<?php
/*
* File: GmailEncodedWordsTest.php
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
 * Class GmailEncodedWordsTest
 *
 * Gmail style message: base64 encoded words folded over several lines (one of them splits the bytes of an
 * emoji, which RFC 2047 forbids but some mailers do), Q encoded display name, an attachment with an RFC 2231
 * filename and an RFC 2047 name, X-Attachment-Id, long folded DKIM / ARC headers.
 *
 * @package Tests\fixtures
 */
class GmailEncodedWordsTest extends FixtureTestCase {

    /**
     * Test the fixture gmail_encoded_words.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("gmail_encoded_words.eml");

        self::assertSame("Grüße 👋 aus Köln – Termin am Montag", (string)$message->subject);
        self::assertSame("Jörg Müller", $message->from->first()->personal);
        self::assertSame("joerg.mueller@gmail.com", $message->from->first()->mail);
        self::assertSame("anna@example.com", $message->delivered_to->first()->mail);
        self::assertSame("joerg.mueller@gmail.com", $message->return_path->first()->mail);
        self::assertFalse($message->spoofed->first());
        self::assertSame("CAJk1=abcdEFGH+1234_5678@mail.gmail.com", (string)$message->message_id);
        self::assertSame("2024-03-04 09:01:52", $message->date->first()->setTimezone("UTC")->format("Y-m-d H:i:s"));
        // unfolding keeps the whitespace of the continuation lines (RFC 5322 2.2.3)
        self::assertStringStartsWith("v=1; a=rsa-sha256; c=relaxed/relaxed;        d=gmail.com;", (string)$message->dkim_signature);
        self::assertStringEndsWith("b=AbCdEf0123456789==", (string)$message->dkim_signature);

        // the base64 encoded text keeps its trailing line break (only the encoded content is trimmed)
        self::assertSame("Hallo zusammen,\n\nwie besprochen: Termin am Montag 📅\n\nJörg\n", $message->getTextBody());
        self::assertSame("<div dir=\"ltr\">Hallo zusammen,<br><br>wie besprochen: Termin am Montag 📅<br><br>Jörg</div>", $message->getHTMLBody());

        self::assertCount(1, $message->getAttachments());
        $attachment = $message->getAttachments()->first();
        self::assertSame("Übersicht 2024.xlsx", $attachment->name);
        self::assertSame("Übersicht 2024.xlsx", $attachment->filename);
        self::assertSame("xlsx", $attachment->getExtension());
        self::assertSame("application", $attachment->type);
        self::assertSame("f_lteqx1a20", $attachment->id);
        self::assertSame("attachment", $attachment->disposition);
        self::assertSame(4 + 3 * 256, strlen($attachment->content));
        self::assertStringStartsWith("PK\x03\x04\x00\x01\x02", $attachment->content);
    }
}
