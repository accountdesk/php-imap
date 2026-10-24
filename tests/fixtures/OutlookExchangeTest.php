<?php
/*
* File: OutlookExchangeTest.php
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
 * Class OutlookExchangeTest
 *
 * Typical Outlook / Exchange message: mixed > related > alternative, an inline image referenced by cid,
 * an attachment with an RFC 2047 encoded name in quotes, "Lastname, Firstname" display names (encoded or
 * quoted, folded inside an address) and the Exchange specific headers.
 *
 * @package Tests\fixtures
 */
class OutlookExchangeTest extends FixtureTestCase {

    /**
     * Test the fixture outlook_exchange.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("outlook_exchange.eml");

        self::assertSame("AW: Angebot für März", (string)$message->subject);
        // only the subject and address headers are decoded, other headers are kept as they are
        self::assertSame("=?iso-8859-1?Q?Angebot_f=FCr_M=E4rz?=", (string)$message->thread_topic);
        self::assertSame("AQHabI4R1x2vNnZ0QkKk0r4b3m5QyLEJb8Mg", (string)$message->thread_index);
        self::assertSame("2024-03-05 09:12:45", $message->date->first()->format("Y-m-d H:i:s"));
        self::assertSame("AM0PR07MB1234A1B2C3D4E5F6A7B8C9D0E1F2A@AM0PR07MB1234.eurprd07.prod.outlook.com", (string)$message->message_id);
        self::assertSame("8f3c2a10-1111-4b5e-9c3e-aaaaaaaaaaaa@example.com", (string)$message->in_reply_to);
        self::assertSame(["8f3c2a10-1111-4b5e-9c3e-aaaaaaaaaaaa@example.com"], $message->references->all());
        self::assertSame("de-DE, en-US", (string)$message->accept_language);
        self::assertSame("yes", (string)$message->x_ms_has_attach);
        self::assertSame("", (string)$message->x_ms_tnef_correlator);
        self::assertSame("-1", (string)$message->x_ms_exchange_organization_scl);

        // the comma is encoded inside the encoded word
        $from = $message->from->first();
        self::assertSame("Müller, Hans", $from->personal);
        self::assertSame("hans.mueller@example.de", $from->mail);
        self::assertSame("Müller, Hans <hans.mueller@example.de>", $from->full);
        // no Reply-To: replies go to the sender
        self::assertSame("hans.mueller@example.de", $message->reply_to->first()->mail);
        self::assertFalse($message->spoofed->first());

        $to = $message->to->all();
        self::assertCount(2, $to);
        self::assertSame(["Schmidt, Anna", "Weber, Tom"], [$to[0]->personal, $to[1]->personal]);
        self::assertSame(["anna.schmidt@example.com", "tom.weber@example.com"], [$to[0]->mail, $to[1]->mail]);
        self::assertSame("Jörg Kühn", $message->cc->first()->personal);

        self::assertSame("Hallo Anna,\r\n\r\nanbei das Angebot für März. Grüße aus Köln\r\n\r\nHans Müller", $message->getTextBody());
        self::assertStringContainsString("<p>anbei das Angebot für März.</p>", $message->getHTMLBody());
        self::assertStringContainsString("cid:image001.png@01DA6E8B.12345670", $message->getHTMLBody());

        $attachments = $message->getAttachments();
        self::assertCount(2, $attachments);

        $image = $attachments->get(0);
        self::assertSame("image001.png", $image->name);
        self::assertSame("image001.png", $image->filename);
        self::assertSame("image", $image->type);
        self::assertSame("image/png", $image->content_type);
        self::assertSame("inline", $image->disposition);
        self::assertSame("image001.png@01DA6E8B.12345670", $image->id);
        self::assertSame("\x89PNG", substr($image->content, 0, 4));
        self::assertSame("image001.png", $image->description);

        $pdf = $attachments->get(1);
        self::assertSame("Angebot März 2024.pdf", $pdf->name);
        self::assertSame("Angebot März 2024.pdf", $pdf->filename);
        self::assertSame("Angebot März 2024.pdf", $pdf->description);
        self::assertSame("application", $pdf->type);
        self::assertSame("application/pdf", $pdf->content_type);
        self::assertSame("attachment", $pdf->disposition);
        self::assertSame("pdf", $pdf->getExtension());
        self::assertStringStartsWith("%PDF-1.4\n", $pdf->content);
        self::assertStringEndsWith("%%EOF\n", $pdf->content);
    }
}
