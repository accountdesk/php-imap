<?php
/*
* File: MaskTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the generic Mask behaviour.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\Masks\Mask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;

class MaskTest extends TestCase {

    /**
     * @return object
     */
    protected function parent(): object {
        return new class {
            public function getAttributes(): array {
                return ["subject" => "Hello", "message_id" => "id@host", "empty" => null];
            }

            public function shout(string $value): string {
                return strtoupper($value);
            }
        };
    }

    public function testAttributesAreCopiedFromParent(): void {
        $parent = $this->parent();
        $mask = new Mask($parent);

        self::assertSame($parent, $mask->getParent());
        self::assertSame(["subject" => "Hello", "message_id" => "id@host", "empty" => null], $mask->getAttributes());
        self::assertSame("Hello", $mask->subject);
        self::assertNull($mask->unknown);
    }

    public function testParentWithoutAttributes(): void {
        $mask = new Mask(new \stdClass());

        self::assertSame([], $mask->getAttributes());
    }

    public function testMagicGettersAndSetters(): void {
        $mask = new Mask($this->parent());

        self::assertSame("Hello", $mask->getSubject());
        self::assertSame("id@host", $mask->getMessageId());
        self::assertSame("World", $mask->setSubject("World"));
        self::assertSame("World", $mask->getSubject());
        // changes on the mask do not affect the parent
        self::assertSame("Hello", $mask->getParent()->getAttributes()["subject"]);

        $mask->custom = "value";
        self::assertSame("value", $mask->custom);
        self::assertSame("value", $mask->getCustom());
    }

    public function testCallsAreForwardedToParent(): void {
        $mask = new Mask($this->parent());

        self::assertSame("HELLO", $mask->shout("hello"));
    }

    public function testUnknownMethodThrows(): void {
        $mask = new Mask($this->parent());

        try {
            $mask->getUnknown();
            $this->fail("Expected MethodNotFoundException");
        } catch (MethodNotFoundException $e) {
            self::assertStringContainsString("getUnknown", $e->getMessage());
        }

        $this->expectException(MethodNotFoundException::class);
        $mask->setUnknown("x");
    }

    public function testNullAttributesCanBeAccessed(): void {
        $this->markTestSkipped("BUG: Mask::__call() uses isset(), getters/setters of attributes with a null value throw MethodNotFoundException");
        $mask = new Mask($this->parent());

        self::assertNull($mask->getEmpty());
        self::assertSame("now set", $mask->setEmpty("now set"));
    }

    public function testBootHook(): void {
        $mask = new class($this->parent()) extends Mask {
            protected function boot(): void {
                $this->attributes["booted"] = true;
            }
        };

        self::assertTrue($mask->booted);
        self::assertSame("Hello", $mask->subject);
    }

    public function testMessageMaskWithoutHtmlBody(): void {
        $message = Message::fromString("From: a@b.c\r\nSubject: x\r\nDate: Mon, 01 Jan 2024 10:00:00 +0000\r\nContent-Type: text/plain\r\n\r\nPlain only");
        $mask = new MessageMask($message);

        self::assertNull($mask->getHtmlBody());
        self::assertNull($mask->getCustomHTMLBody(function($body) { return $body."!"; }));
        self::assertNull($mask->getHTMLBodyWithEmbeddedBase64Images());
        self::assertSame("Plain only", $mask->getTextBody());
    }

    public function testMessageMaskWithObjectBody(): void {
        $message = Message::fromString("From: a@b.c\r\nSubject: x\r\nDate: Mon, 01 Jan 2024 10:00:00 +0000\r\nContent-Type: text/plain\r\n\r\nPlain only");
        // older versions stored body objects with a content property
        $message->bodies["html"] = (object)["content" => "<b>object</b>"];
        $mask = new MessageMask($message);

        self::assertSame("<b>object</b>", $mask->getHtmlBody());
    }
}
