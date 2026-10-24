<?php
/*
* File: AttributeDetailTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Attribute array access / invocation and Address string conversion.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;

class AttributeDetailTest extends TestCase {

    public function testInvokeReturnsStringOrArray(): void {
        self::assertSame("one", (new Attribute("a", "one"))());
        self::assertSame(["one", "two"], (new Attribute("a", ["one", "two"]))());
        self::assertSame("", (new Attribute("a"))());
    }

    public function testArrayAccess(): void {
        $attribute = new Attribute("a", ["one", "two"]);

        self::assertTrue(isset($attribute[1]));
        self::assertFalse(isset($attribute[5]));
        self::assertSame("two", $attribute[1]);
        self::assertNull($attribute[5]);

        $attribute[1] = "changed";
        self::assertSame("changed", $attribute[1]);

        // appending without a key
        $attribute[] = "three";
        self::assertSame(["one", "changed", "three"], $attribute->all());

        unset($attribute[0]);
        self::assertSame([1 => "changed", 2 => "three"], $attribute->all());
        self::assertSame("changed", $attribute->first());
        self::assertSame("three", $attribute->last());

        $attribute["key"] = "value";
        self::assertSame("value", $attribute->get("key"));
        self::assertTrue($attribute->exist("key"));
    }

    public function testMergeStrictAndMap(): void {
        $attribute = new Attribute("a", ["x", "y"]);
        $attribute->add(["y", "z"], true);
        self::assertSame(["x", "y", "z"], $attribute->all());

        $attribute->add(["y"]);
        self::assertSame(["x", "y", "z", "y"], $attribute->all());
        self::assertSame(["X", "Y", "Z", "Y"], $attribute->map("strtoupper"));
        self::assertTrue($attribute->contains("z"));
        self::assertFalse($attribute->contains("Z"));

        // null values are ignored
        $attribute->add(null);
        self::assertSame(4, $attribute->count());
        $attribute->remove(99);
        self::assertSame(4, $attribute->count());
    }

    public function testAddressToString(): void {
        $address = new Address((object)["personal" => "Max", "mailbox" => "max", "host" => "example.com"]);

        self::assertSame("Max <max@example.com>", $address->toString());
        self::assertSame("", (new Address((object)[]))->toString());
        self::assertSame("max", (new Address((object)["mailbox" => "max"]))->toString());
        self::assertSame("Full <f@x>", (new Address((object)["full" => "Full <f@x>", "mail" => "f@x"]))->toString());
        // null values are treated as empty strings
        self::assertSame("max@example.com", (new Address((object)["personal" => null, "mailbox" => "max", "host" => "example.com"]))->toString());
    }
}
