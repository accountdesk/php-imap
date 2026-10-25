<?php
/*
* File: Rfc2231FilenamesTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  -
*/

namespace Tests\fixtures;

use Webklex\PHPIMAP\Attachment;

/**
 * Class Rfc2231FilenamesTest
 *
 * @package Tests\fixtures
 */
class Rfc2231FilenamesTest extends FixtureTestCase {

    /**
     * Test the fixture rfc2231_filenames.eml
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("rfc2231_filenames.eml");

        self::assertSame("See attachments.", $message->getTextBody());
        $attachments = $message->getAttachments();
        self::assertSame(4, $attachments->count());

        /** @var Attachment $invoice */
        $invoice = $attachments->get(0);
        self::assertSame("€ Rechnung März.pdf", $invoice->filename);
        self::assertSame("€ Rechnung März.pdf", $invoice->name);
        self::assertSame("%PDF-1.4 invoice", $invoice->content);
        self::assertSame("pdf", $invoice->getExtension());

        // continuation parameters (filename*0*, filename*1*) are joined
        $continued = $attachments->get(1);
        self::assertSame("Sehr langer Dateiname ü.txt", $continued->filename);
        self::assertSame("Inhalt mit Umlaut ü", $continued->content);
        self::assertSame("text/plain", $continued->content_type);

        // path components are stripped from file names
        $passwd = $attachments->get(2);
        self::assertSame("etcpasswd", $passwd->filename);
        self::assertStringNotContainsString("/", $passwd->name);

        // attachment content is decoded from the transfer encoding but keeps its charset
        $csv = $attachments->get(3);
        self::assertSame("Liste.csv", $csv->filename);
        self::assertSame("Name;Ort\r\nM\xFCller;K\xF6ln", $csv->content);
        self::assertSame("csv", $csv->getExtension());
    }

    /**
     * Attachments can be saved to disk using a sanitized file name
     *
     * @return void
     */
    public function testSave() : void {
        $message = $this->getFixture("rfc2231_filenames.eml");
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR."php-imap-".uniqid();
        mkdir($dir);
        try {
            $invoice = $message->getAttachments()->get(0);
            self::assertTrue($invoice->save($dir));
            self::assertSame("%PDF-1.4 invoice", file_get_contents($dir.DIRECTORY_SEPARATOR."€ Rechnung März.pdf"));

            // a custom file name is sanitized as well
            self::assertTrue($invoice->save($dir, "../outside.pdf"));
            self::assertFileExists($dir.DIRECTORY_SEPARATOR."outside.pdf");
            self::assertFileDoesNotExist(dirname($dir).DIRECTORY_SEPARATOR."outside.pdf");
        } finally {
            array_map("unlink", glob($dir.DIRECTORY_SEPARATOR."*"));
            rmdir($dir);
        }
    }
}
