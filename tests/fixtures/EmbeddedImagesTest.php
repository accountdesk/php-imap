<?php
/*
* File: EmbeddedImagesTest.php
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
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;

/**
 * Class EmbeddedImagesTest
 *
 * @package Tests\fixtures
 */
class EmbeddedImagesTest extends FixtureTestCase {

    /**
     * Test the fixture embedded_images.eml (multipart/mixed > multipart/related with cid images)
     *
     * @return void
     */
    public function testFixture() : void {
        $message = $this->getFixture("embedded_images.eml");

        self::assertEquals("Embedded images", $message->subject);
        self::assertFalse($message->hasTextBody());
        self::assertTrue($message->hasHTMLBody());
        // quoted printable soft line breaks are removed
        self::assertSame(
            '<p>Logo: <img src="cid:logo@example.com"></p><p>Pixel: <img src="cid:pixel@example.com"></p>',
            $message->getHTMLBody()
        );

        $attachments = $message->getAttachments();
        self::assertSame(3, $attachments->count());
        self::assertTrue($message->hasAttachments());

        /** @var Attachment $logo */
        $logo = $attachments->get(0);
        self::assertSame("logo@example.com", $logo->id);
        self::assertSame("inline", $logo->disposition);
        self::assertSame("image/gif", $logo->content_type);
        self::assertSame("image/gif", $logo->getMimeType());
        self::assertStringStartsWith("GIF89a", $logo->content);
        // an inline part without a filename is named after its hash
        self::assertSame($logo->hash, $logo->filename);
        self::assertSame($logo->hash, $logo->name);
        self::assertSame(1, $logo->part_number);

        /** @var Attachment $pixel */
        $pixel = $attachments->get(1);
        self::assertSame("pixel@example.com", $pixel->id);
        self::assertSame("pixel.png", $pixel->name);
        self::assertSame("png", $pixel->getExtension());

        /** @var Attachment $report */
        $report = $attachments->get(2);
        self::assertSame("attachment", $report->disposition);
        self::assertSame("report.pdf", $report->filename);
        self::assertSame("%PDF-1.4\n%fake pdf\n", $report->content);
        // without Content-ID the hash is used as id
        self::assertSame($report->hash, $report->id);
        self::assertSame(3, $report->part_number);
    }

    /**
     * The attachment type should reflect the content type of the part
     *
     * @return void
     */
    public function testAttachmentType() : void {
        $message = $this->getFixture("embedded_images.eml");

        self::assertSame("image", $message->getAttachments()->get(0)->type);
        self::assertSame("application", $message->getAttachments()->get(2)->type);
    }

    /**
     * Test the message mask helpers
     *
     * @return void
     */
    public function testMessageMask() : void {
        $message = $this->getFixture("embedded_images.eml");

        /** @var MessageMask $mask */
        $mask = $message->mask();
        self::assertInstanceOf(MessageMask::class, $mask);
        self::assertSame($message, $mask->getParent());
        self::assertSame($message->getHTMLBody(), $mask->getHtmlBody());
        self::assertSame($message->getHTMLBody(), $mask->getCustomHTMLBody());

        $html = $mask->getHTMLBodyWithEmbeddedBase64Images();
        self::assertStringNotContainsString("cid:", $html);
        $logo = $message->getAttachments()->get(0);
        self::assertStringContainsString('src="data:image/gif;base64, '.base64_encode($logo->content).'"', $html);
        $pixel = $message->getAttachments()->get(1);
        self::assertStringContainsString('src="data:image/png;base64, '.base64_encode($pixel->content).'"', $html);

        // custom callback is applied once per attachment
        $calls = 0;
        $body = $mask->getCustomHTMLBody(function($body, $attachment) use (&$calls) {
            $calls++;
            return $body."[".$attachment->name."]";
        });
        self::assertSame(3, $calls);
        self::assertStringEndsWith("[report.pdf]", $body);

        // attributes of the message are accessible through the mask
        self::assertSame("embedded-images@example.com", (string)$mask->message_id);
        self::assertSame("Embedded images", (string)$mask->getSubject());
        self::assertNull($mask->unknown_attribute);
        // unknown getters are forwarded to the message
        self::assertSame($message->getAttachments(), $mask->getAttachments());
    }

    /**
     * Test the attachment mask helpers
     *
     * @return void
     */
    public function testAttachmentMask() : void {
        $message = $this->getFixture("embedded_images.eml");
        $logo = $message->getAttachments()->get(0);

        /** @var AttachmentMask $mask */
        $mask = $logo->mask();
        self::assertInstanceOf(AttachmentMask::class, $mask);
        self::assertSame(base64_encode($logo->content), $mask->getContentBase64Encoded());
        self::assertSame("data:image/gif;base64,".base64_encode($logo->content), $mask->getImageSrc());
        self::assertSame("logo@example.com", $mask->id);
        self::assertSame("logo@example.com", $mask->getId());
    }
}
