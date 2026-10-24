<?php
/*
* File: ImapProtocolResponseTest.php
* Category: Test
*
* Description:
*  Error paths and the Response object of ImapProtocol against the scripted fake IMAP server: NO / BAD /
*  unknown status, BYE, lost connections, timeouts, debug output and the recorded conversation.
*/

namespace Tests;

use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

class ImapProtocolResponseTest extends ScriptedServerTestCase {

    public function testNoResponseThrowsServerErrorWithTheServerText(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["* 3 EXISTS", "{tag} NO [SERVERBUG] Internal error occurred. Refer to server log for more information."]),
        ]);

        try {
            $protocol->noop();
            self::fail("ImapServerErrorException expected");
        } catch (ImapServerErrorException $e) {
            self::assertStringContainsString("NO [SERVERBUG] Internal error occurred.", $e->getMessage());
        }
        $this->assertScriptCompleted();
    }

    /**
     * BAD (RFC 3501 7.1.3: protocol level error in the client's command) is reported as ImapServerErrorException
     * as well - ImapBadRequestException is only used for an unknown status.
     */
    public function testBadResponseThrowsServerError(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["{tag} BAD Error in IMAP command NOOP: Unknown command."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("BAD Error in IMAP command NOOP: Unknown command.");
        $protocol->noop();
    }

    public function testUnknownStatusThrowsBadRequest(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["{tag} PREAUTH what?"]),
        ]);

        $this->expectException(ImapBadRequestException::class);
        $this->expectExceptionMessage("PREAUTH what?");
        $protocol->noop();
    }

    public function testUnparsedNoResponse(): void {
        $protocol = $this->connectedProtocol([
            self::step('CREATE "INBOX"', ["{tag} NO [ALREADYEXISTS] Mailbox already exists"]),
        ]);

        try {
            $protocol->createFolder("INBOX");
            self::fail("ImapServerErrorException expected");
        } catch (ImapServerErrorException $e) {
            self::assertStringStartsWith("NO [ALREADYEXISTS] Mailbox already exists", $e->getMessage());
        }
    }

    public function testUntaggedByeAndClosedConnection(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["* BYE Server shutting down."], ["close" => true]),
        ]);

        try {
            $protocol->noop();
            self::fail("RuntimeException expected");
        } catch (RuntimeException $e) {
            self::assertSame("empty response", $e->getMessage());
        }
        $this->assertScriptCompleted();
    }

    public function testConnectionClosedInTheMiddleOfAResponseLine(): void {
        $protocol = $this->connectedProtocol([
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" INBOX', ["raw" => '* LIST (\HasNoChildren) "/" Arch']], ["close" => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $protocol->folders();
    }

    public function testTimeout(): void {
        $port = $this->startServer([[
            "steps" => [self::step("NOOP", ["{tag} OK NOOP completed."], ["delay" => 1300])],
        ]]);
        $protocol = $this->protocol();
        $protocol->setConnectionTimeout(1);
        $protocol->connect("127.0.0.1", $port);

        $start = microtime(true);
        try {
            $protocol->noop();
            self::fail("RuntimeException expected");
        } catch (RuntimeException $e) {
            self::assertSame("empty response", $e->getMessage());
        }
        self::assertLessThan(1.25, microtime(true) - $start, "the stream timeout was not applied");
        self::assertTrue($protocol->meta()["timed_out"]);

        // a timed out connection is dropped without LOGOUT
        $response = $protocol->logout();
        self::assertSame([], $response->getCommands());
        self::assertNull($protocol->getStream());
        self::assertSame(["TAG1 NOOP"], $this->receivedLines(null, true));
    }

    public function testDebugOutput(): void {
        $port = $this->startServer([[
            "greeting" => "* OK Dovecot ready.",
            "steps"    => [self::step("NOOP", ["{tag} OK NOOP completed."])],
        ]]);
        $protocol = $this->protocol();
        $protocol->enableDebug();

        $this->expectOutputString("<< * OK Dovecot ready.\r\n>> TAG1 NOOP\r\n\n<< TAG1 OK NOOP completed.\r\n");
        $protocol->connect("127.0.0.1", $port);
        $protocol->noop();
        $protocol->disableDebug();
        $this->assertScriptCompleted();
    }

    public function testResponseExceptionContainsTheConversationInDebugMode(): void {
        $protocol = $this->connectedProtocol([
            self::step('SELECT "Nope"', ["{tag} NO Mailbox doesn't exist: Nope"]),
        ]);
        $protocol->enableDebug();

        ob_start();
        try {
            $response = $protocol->selectFolder("Nope");
        } finally {
            ob_end_clean();
            $protocol->disableDebug();
        }

        try {
            $response->validatedData();
            self::fail("ResponseException expected");
        } catch (ResponseException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString("- request failed", $message);
            self::assertStringContainsString("Commands send:\n\tTAG1 SELECT \"Nope\"\\r\\n", $message);
            self::assertStringContainsString("Responses received:\n\tTAG1 NO Mailbox doesn't exist: Nope\\r\\n", $message);
        }
    }

    public function testResponseRecordsLiteralCommandsAndContinuations(): void {
        $protocol = $this->connectedProtocol([
            self::step("LOGIN \"user\" {8}\r\nsec\r\nret", ["{tag} OK Logged in"]),
        ]);

        $response = $protocol->login("user", "sec\r\nret");

        self::assertSame(["TAG1 LOGIN \"user\" {8}\r\n", "sec\r\nret\r\n"], $response->getCommands());
        self::assertSame(["+ OK\r\n", "TAG1 OK Logged in\r\n"], $response->getResponse());
        self::assertSame(1, $response->Noun());
        $this->assertScriptCompleted();
    }

    public function testRequestAndResponseUnparsed(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["* 4 EXISTS", "{tag} OK NOOP completed."]),
            self::step("NOOP", ["* 4 EXISTS", "{tag} OK NOOP completed."]),
        ]);

        self::assertSame(["4 EXISTS\r\n", "OK NOOP completed.\r\n"], $protocol->requestAndResponse("NOOP", [], true)->data());
        self::assertSame([["4", "EXISTS"], ["OK", "NOOP", "completed."]], $protocol->requestAndResponse("NOOP")->data());
        $this->assertScriptCompleted();
    }
}
