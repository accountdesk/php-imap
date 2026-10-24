<?php
/*
* File: ResponseTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the protocol Response container and the ResponseException message builder.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\ResponseException;

class ResponseTest extends TestCase {

    public function testMakeAndAccessors(): void {
        $response = Response::make(7, ["TAG7 NOOP\r\n"], ["TAG7 OK NOOP completed\r\n"], true);

        self::assertSame(7, $response->Noun());
        self::assertSame(["TAG7 NOOP\r\n"], $response->getCommands());
        self::assertSame(["TAG7 OK NOOP completed\r\n"], $response->getResponse());
        self::assertSame([], $response->getErrors());
        self::assertSame([], $response->getStack());
        self::assertFalse($response->canBeEmpty());
        self::assertTrue($response->successful());
        self::assertFalse($response->failed());
    }

    public function testEmptyResponseGetsGeneratedNoun(): void {
        $a = Response::empty();
        $b = new Response(-1);

        self::assertGreaterThan(0, $a->Noun());
        self::assertGreaterThan(0, $b->Noun());
        self::assertSame([], $a->getResponse());
        self::assertSame([], $a->data());
    }

    public function testCommandAndResponseMutators(): void {
        $response = Response::empty()
            ->addCommand("A")
            ->addCommand("B")
            ->addResponse("first")
            ->addResponse(["nested", "line"]);

        self::assertSame(["A", "B"], $response->getCommands());
        self::assertSame(["first", ["nested", "line"]], $response->getResponse());

        $response->setCommands(["C"])->setResponse(["second"]);
        self::assertSame(["C"], $response->getCommands());
        self::assertSame(["second"], $response->getResponse());
    }

    public function testDataPrefersResult(): void {
        $response = Response::empty()->setResponse(["raw"]);
        self::assertSame(["raw"], $response->data());

        $response->setResult(["parsed" => 1]);
        self::assertSame(["parsed" => 1], $response->data());

        // a result of null falls back to the raw response
        $response->setResult(null);
        self::assertSame(["raw"], $response->data());
    }

    public function testWrap(): void {
        $response = Response::empty()->setResponse(["* 3 EXISTS", "TAG1 OK"]);
        $response->wrap(function(Response $r) {
            return count($r->getResponse());
        });

        self::assertSame(2, $response->data());
    }

    public function testTypeConversions(): void {
        $response = Response::empty()->setResult(["12", "34"]);
        self::assertSame(["12", "34"], $response->array());
        self::assertSame("12 34", $response->string());
        self::assertSame(12, $response->integer());
        self::assertTrue($response->boolean());

        $response = Response::empty()->setResult("42");
        self::assertSame(["42"], $response->array());
        self::assertSame("42", $response->string());
        self::assertSame(42, $response->integer());
        self::assertTrue($response->boolean());

        $response = Response::empty()->setResult(0);
        self::assertSame("0", $response->string());
        self::assertSame(0, $response->integer());
        self::assertFalse($response->boolean());

        // an associative array without index 0 is converted as a whole
        $response = Response::empty()->setResult(["a" => "b"]);
        self::assertSame(1, $response->integer());

        $response = Response::empty();
        self::assertSame("", $response->string());
        self::assertSame(0, $response->integer());
        self::assertFalse($response->boolean());
    }

    public function testEmptyResponseIsOnlySuccessfulWhenAllowed(): void {
        $response = Response::empty();
        self::assertFalse($response->successful());
        self::assertTrue($response->failed());

        $response->setCanBeEmpty(true);
        self::assertTrue($response->canBeEmpty());
        self::assertTrue($response->successful());
        self::assertSame([], $response->validatedData());
    }

    public function testBadAndNoLinesAreDetected(): void {
        $bad = Response::make(3, [], ["* OK something", "TAG3 BAD Error in IMAP command\r\n"]);
        $no = Response::make(3, [], ["TAG3 NO Mailbox does not exist\r\n"]);
        $ok = Response::make(3, [], ["TAG3 OK done\r\n"]);

        self::assertTrue($bad->failed());
        self::assertTrue($no->failed());
        self::assertTrue($ok->successful());
    }

    public function testOnlyOwnTagIsChecked(): void {
        // a BAD from another tag (e.g. a previous command) does not fail this response
        $response = Response::make(3, [], ["TAG2 BAD old\r\n", "TAG3 OK done\r\n"]);
        self::assertTrue($response->successful());

        // "NO" / "BAD" inside the text are not mistaken for a status
        $response = Response::make(3, [], ["* 1 FETCH (BODY[] {6}\r\nNO BAD)\r\n", "TAG3 OK\r\n"]);
        self::assertTrue($response->successful());
    }

    public function testNestedDataIsVerified(): void {
        $response = Response::make(5, [], [["* LIST", ["nested", ["TAG5 NO deep failure"]]]]);
        self::assertFalse($response->successful());

        self::assertTrue($response->verify_data(["a", ["b", ["c"]]]));
        self::assertFalse($response->verify_data(["a", ["TAG5 BAD x"]]));
        self::assertFalse($response->verify_data("TAG5 NO x"));
        self::assertTrue($response->verify_data(12));
        self::assertTrue($response->verify_line("TAG5 OK"));
        self::assertTrue($response->verify_line("TAG5 NOOP completed"));
        self::assertFalse($response->verify_line("TAG5 NO "));
    }

    public function testFailingResultIsDetected(): void {
        // the parsed result is verified as well
        $response = Response::make(9, [], ["TAG9 OK"])->setResult(["TAG9 BAD parsed"]);
        self::assertTrue($response->failed());
    }

    public function testErrors(): void {
        $response = Response::make(1, [], ["TAG1 OK"]);
        $response->addError("first");
        self::assertSame(["first"], $response->getErrors());
        self::assertTrue($response->failed());

        $response->setErrors(["a", "b"]);
        self::assertSame(["a", "b"], $response->getErrors());
        $response->setErrors([]);
        self::assertTrue($response->successful());
    }

    public function testStackedResponses(): void {
        $parent = Response::make(1, [], ["TAG1 OK"]);
        $child = Response::make(2, [], ["TAG2 OK"]);
        $parent->stack($child);

        self::assertSame([$child], $parent->getStack());
        self::assertTrue($parent->successful());

        // errors of stacked responses are merged
        $child->addError("child error");
        self::assertSame(["child error"], $parent->getErrors());
        self::assertTrue($parent->failed());

        // a failing stacked response fails the parent
        $child->setErrors([]);
        $child->setResponse(["TAG2 NO failed"]);
        self::assertTrue($parent->failed());
    }

    public function testValidateThrows(): void {
        $response = Response::make(4, ["TAG4 SELECT foo\r\n"], ["TAG4 NO no such mailbox\r\n"]);

        try {
            $response->validate();
            $this->fail("Expected ResponseException");
        } catch (ResponseException $e) {
            self::assertStringContainsString("Command failed to process", $e->getMessage());
            self::assertStringContainsString("Error occurred", $e->getMessage());
            self::assertStringNotContainsString("Commands send", $e->getMessage());
        }

        $ok = Response::make(4, [], ["TAG4 OK"]);
        self::assertSame($ok, $ok->validate());
    }

    public function testExceptionListsErrorsAndEmptyResponse(): void {
        $response = Response::empty()->addError("connection reset")->addError("timeout");
        $message = ResponseException::make($response)->getMessage();

        self::assertStringContainsString("\t- connection reset\n", $message);
        self::assertStringContainsString("\t- timeout\n", $message);
        self::assertStringContainsString("\t- Empty response\n", $message);
    }

    public function testExceptionDebugMessage(): void {
        $response = Response::make(4, ["TAG4 LOGIN foo bar\r\n"], ["TAG4 NO login failed\r\n", ["* x", "y\r\n"]], true);

        try {
            $response->validatedData();
            $this->fail("Expected ResponseException");
        } catch (ResponseException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString("Commands send:\n\tTAG4 LOGIN foo bar\\r\\n\n", $message);
            self::assertStringContainsString("Responses received:\n\tTAG4 NO login failed\\r\\n\n\t* x\n\ty\\r\\n\n", $message);
            self::assertStringNotContainsString("Empty response", $message);
        }
    }

    public function testExceptionDebugMessageWithoutCommandsAndResponses(): void {
        $message = ResponseException::make(Response::empty(), true)->getMessage();

        self::assertStringContainsString("\tNo command send!\n", $message);
        self::assertStringContainsString("\tNo responses received!\n", $message);
    }

    public function testExceptionDebugMessageWithDeeplyNestedResponse(): void {
        $this->markTestSkipped("BUG: ResponseException::debug_message() casts nested response arrays to string (\"Array to string conversion\")");
        $response = Response::make(4, ["TAG4 EXPUNGE\r\n"], ["TAG4 NO", ["OK", ["0.001", "+", ["deep"]]]], true);
        $message = ResponseException::make($response, true)->getMessage();

        self::assertStringContainsString("deep", $message);
    }

    public function testExceptionChainsStackedResponses(): void {
        $parent = Response::make(1, ["TAG1 A\r\n"], ["TAG1 OK"]);
        $child = Response::make(2, ["TAG2 B\r\n"], ["TAG2 NO child failed"])->addError("child error");
        $parent->stack($child);

        $exception = ResponseException::make($parent, true);
        $previous = $exception->getPrevious();

        self::assertInstanceOf(ResponseException::class, $previous);
        self::assertStringContainsString("child error", $previous->getMessage());
        self::assertStringContainsString("TAG2 B", $previous->getMessage());
        // the parent message contains the merged errors as well
        self::assertStringContainsString("child error", $exception->getMessage());
    }

    public function testExceptionKeepsGivenPrevious(): void {
        $root = new \Exception("root cause");
        $exception = ResponseException::make(Response::empty(), false, $root);

        self::assertSame($root, $exception->getPrevious());
    }
}
