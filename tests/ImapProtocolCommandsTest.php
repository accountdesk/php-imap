<?php
/*
* File: ImapProtocolCommandsTest.php
* Category: Test
*
* Description:
*  ImapProtocol against the scripted fake IMAP server (support/scripted_imap_server.php): the exact
*  commands sent and the parsing of real server responses (RFC 3501 examples, Dovecot, Gmail).
*/

namespace Tests;

use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;

class ImapProtocolCommandsTest extends ScriptedServerTestCase {

    /** @var string|false previous error_log setting */
    protected string|false $error_log = false;

    protected function setUp(): void {
        parent::setUp();
        // authenticate() reports failures with error_log() - keep the test output clean
        $this->error_log = ini_set("error_log", "/dev/null");
    }

    protected function tearDown(): void {
        ini_set("error_log", $this->error_log === false ? "" : $this->error_log);
        parent::tearDown();
    }

    /*
     * Connection
     */

    public function testConnectReadsGreetingAndConnectedSendsNoop(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["{tag} OK NOOP completed."]),
        ]);

        self::assertTrue($protocol->connected());
        self::assertIsResource($protocol->getStream());
        self::assertFalse($protocol->meta()["timed_out"]);
        $this->assertScriptCompleted();
    }

    public function testConnectedIsFalseWithoutStream(): void {
        $protocol = $this->protocol();
        self::assertFalse($protocol->connected());
        self::assertTrue($protocol->meta()["timed_out"]);
        self::assertFalse($protocol->getStream());
    }

    public function testConnectedIsFalseWhenNoopFails(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["{tag} NO [UNAVAILABLE] Server shutting down."]),
        ]);

        self::assertFalse($protocol->connected());
        $this->assertScriptCompleted();
    }

    public function testConnectedIsFalseWhenServerClosedTheConnection(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["* BYE Disconnected for inactivity."], ["close" => true]),
        ]);

        self::assertFalse($protocol->connected());
        $this->assertScriptCompleted();
    }

    public function testConnectFailsOnByeGreeting(): void {
        $port = $this->startServer([["greeting" => "* BYE Too many simultaneous connections.", "close_after_greeting" => true]]);

        try {
            $this->protocol()->connect("127.0.0.1", $port);
            self::fail("ConnectionFailedException expected");
        } catch (ConnectionFailedException $e) {
            self::assertSame("connection failed", $e->getMessage());
            self::assertInstanceOf(ConnectionFailedException::class, $e->getPrevious());
            self::assertSame("connection refused", $e->getPrevious()->getMessage());
        }
    }

    public function testConnectFailsWhenServerClosesWithoutGreeting(): void {
        $port = $this->startServer([["greeting" => null, "close_after_greeting" => true]]);

        try {
            $this->protocol()->connect("127.0.0.1", $port);
            self::fail("ConnectionFailedException expected");
        } catch (ConnectionFailedException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertSame("empty response", $e->getPrevious()->getMessage());
        }
    }

    public function testConnectFailsWhenNothingListens(): void {
        $port = self::unusedPort();

        try {
            // stream_socket_client() reports the refused connection as a warning as well
            @$this->protocol()->connect("127.0.0.1", $port);
            self::fail("ConnectionFailedException expected");
        } catch (ConnectionFailedException $e) {
            self::assertSame("connection failed", $e->getMessage());
            self::assertInstanceOf(ConnectionFailedException::class, $e->getPrevious());
            self::assertGreaterThan(0, $e->getPrevious()->getCode(), "errno of the failed connect");
        }
    }

    public function testStartTlsRejectedByServer(): void {
        $port = $this->startServer([[
            "greeting" => "* OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR LOGIN-REFERRALS ID ENABLE IDLE LOGINDISABLED] Dovecot ready.",
            "steps"    => [self::step("STARTTLS", ["{tag} BAD Error in IMAP command STARTTLS: TLS support isn't enabled."])],
        ]]);

        try {
            $this->protocol("starttls")->connect("127.0.0.1", $port);
            self::fail("ConnectionFailedException expected");
        } catch (ConnectionFailedException $e) {
            self::assertInstanceOf(ImapServerErrorException::class, $e->getPrevious());
            self::assertStringContainsString("TLS support isn't enabled", $e->getPrevious()->getMessage());
        }
        $this->assertScriptCompleted();
    }

    public function testStartTlsFailsWhenTheHandshakeFails(): void {
        $port = $this->startServer([[
            "steps" => [self::step("STARTTLS", ["{tag} OK Begin TLS negotiation now."], ["close" => true])],
        ]]);

        try {
            // the failed handshake is reported as a warning as well
            @$this->protocol("starttls")->connect("127.0.0.1", $port);
            self::fail("ConnectionFailedException expected");
        } catch (ConnectionFailedException $e) {
            self::assertInstanceOf(ConnectionFailedException::class, $e->getPrevious());
            self::assertSame("failed to enable TLS", $e->getPrevious()->getMessage());
        }
        $this->assertScriptCompleted();
    }

    public function testStartTlsUpgradesTheConnection(): void {
        $cert = $this->selfSignedCertificate();
        $port = $this->startServer([[
            "local_cert" => $cert["cert"],
            "local_pk"   => $cert["key"],
            "steps"      => [
                self::step("STARTTLS", ["{tag} OK Begin TLS negotiation now."], ["starttls" => true]),
                self::step("CAPABILITY", ["* CAPABILITY IMAP4rev1 AUTH=PLAIN", "{tag} OK Capability completed."]),
            ],
        ]]);

        $protocol = $this->protocol("starttls");
        $protocol->connect("127.0.0.1", $port);

        $meta = $protocol->meta();
        self::assertArrayHasKey("crypto", $meta);
        self::assertNotEmpty($meta["crypto"]["protocol"]);
        self::assertContains("AUTH=PLAIN", $protocol->getCapabilities()->validatedData());
        $this->assertScriptCompleted();
    }

    public function testImplicitTlsWithoutCertificateValidation(): void {
        $cert = $this->selfSignedCertificate();
        $port = $this->startServer([[
            "tls"        => true,
            "local_cert" => $cert["cert"],
            "local_pk"   => $cert["key"],
            "steps"      => [self::step("NOOP", ["{tag} OK NOOP completed."])],
        ]]);

        $protocol = $this->protocol("ssl", false);
        $protocol->setSslOptions(["verify_peer" => true, "allow_self_signed" => true]);
        $protocol->connect("127.0.0.1", $port);

        self::assertTrue($protocol->connected());
        self::assertNotEmpty($protocol->meta()["crypto"]["protocol"]);
        $this->assertScriptCompleted();
    }

    public function testImplicitTlsFailsForSelfSignedCertificateWithValidation(): void {
        $cert = $this->selfSignedCertificate();
        $port = $this->startServer([[
            "tls"        => true,
            "local_cert" => $cert["cert"],
            "local_pk"   => $cert["key"],
        ]]);

        $this->expectException(ConnectionFailedException::class);
        // the TLS handshake fails, PHP reports it as a warning as well
        @$this->protocol("ssl", true)->connect("127.0.0.1", $port);
    }

    /*
     * LOGIN / AUTHENTICATE
     */

    public function testLogin(): void {
        $protocol = $this->connectedProtocol([self::loginStep()]);

        $response = $protocol->login("user@example.com", "secret");

        self::assertTrue($response->successful());
        $data = $response->validatedData();
        self::assertCount(1, $data);
        self::assertStringStartsWith("OK [CAPABILITY IMAP4rev1", $data[0]);
        self::assertSame(["TAG1 LOGIN \"user@example.com\" \"secret\"\r\n"], $response->getCommands());
        self::assertSame(["TAG1 " . $data[0]], $response->getResponse());
        $this->assertScriptCompleted();
    }

    public function testLoginEscapesQuotesAndBackslashesInThePassword(): void {
        $protocol = $this->connectedProtocol([
            self::step('LOGIN "us\\"er" "p@ss \\\\w\\"ord%*()"', ["{tag} OK Logged in"]),
        ]);

        $protocol->login('us"er', 'p@ss \\w"ord%*()');
        $this->assertScriptCompleted();
    }

    public function testLoginSendsAPasswordWithLineBreakAsLiteral(): void {
        $protocol = $this->connectedProtocol([
            self::step("LOGIN \"user\" {8}\r\nsec\r\nret", ["{tag} OK Logged in"]),
        ]);

        $protocol->login("user", "sec\r\nret");
        $this->assertScriptCompleted();
    }

    public function testLoginFailureThrowsServerError(): void {
        $protocol = $this->connectedProtocol([
            self::step('LOGIN "user" "wrong"', ["{tag} NO [AUTHENTICATIONFAILED] Authentication failed."]),
        ]);

        try {
            $protocol->login("user", "wrong");
            self::fail("ImapServerErrorException expected");
        } catch (ImapServerErrorException $e) {
            self::assertStringContainsString("NO [AUTHENTICATIONFAILED] Authentication failed.", $e->getMessage());
        }
        $this->assertScriptCompleted();
    }

    public function testLoginFailsWhenTheConnectionIsLost(): void {
        $protocol = $this->connectedProtocol([
            self::step('LOGIN "user" "secret"', [], ["close" => true]),
        ]);

        $this->expectException(AuthFailedException::class);
        $protocol->login("user", "secret");
    }

    public function testAuthenticateXoauth2(): void {
        $token = base64_encode("user=user@gmail.com\1auth=Bearer ya29.token\1\1");
        $protocol = $this->connectedProtocol([
            self::step("AUTHENTICATE XOAUTH2 $token", [
                "* CAPABILITY IMAP4rev1 UNSELECT IDLE NAMESPACE QUOTA ID XLIST CHILDREN X-GM-EXT-1 UIDPLUS COMPRESS=DEFLATE ENABLE MOVE CONDSTORE ESEARCH UTF8=ACCEPT LIST-EXTENDED LIST-STATUS LITERAL- SPECIAL-USE APPENDLIMIT=35651584",
                "{tag} OK user@gmail.com authenticated (Success)",
            ]),
        ], ["greeting" => "* OK Gimap ready for requests from 127.0.0.1 a1mb12345678abc"]);

        $response = $protocol->authenticate("user@gmail.com", "ya29.token");

        self::assertSame([], $response->getErrors());
        self::assertStringStartsWith("OK user@gmail.com authenticated", $response->validatedData()[0]);
        $this->assertScriptCompleted();
    }

    public function testAuthenticateXoauth2ImmediateFailure(): void {
        $protocol = $this->connectedProtocol([
            self::step("/^TAG1 AUTHENTICATE XOAUTH2 \\S+$/", ["{tag} NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)"]),
        ]);

        $response = $protocol->authenticate("user@gmail.com", "expired");

        self::assertTrue($response->failed());
        self::assertCount(1, $response->getErrors());
        self::assertStringContainsString("Invalid credentials (Failure)", $response->getErrors()[0]);
        $this->expectException(ResponseException::class);
        $response->validatedData();
    }

    public function testAuthenticateXoauth2BadCommand(): void {
        $protocol = $this->connectedProtocol([
            self::step("/^TAG1 AUTHENTICATE XOAUTH2 \\S+$/", ["{tag} BAD Unsupported authentication mechanism."]),
        ]);

        $response = $protocol->authenticate("user", "token");

        self::assertTrue($response->failed());
        self::assertStringContainsString("got failure response: BAD Unsupported authentication mechanism.", $response->getErrors()[0]);
    }

    /**
     * Gmail answers invalid XOAUTH2 credentials with a continuation request carrying a base64 encoded JSON
     * error. The failure must be reported once the server completes the command.
     */
    public function testAuthenticateXoauth2ErrorContinuation(): void {
        $error = base64_encode('{"status":"400","schemes":"Bearer","scope":"https://mail.google.com/"}');
        $protocol = $this->connectedProtocol([
            self::step("/^TAG1 AUTHENTICATE XOAUTH2 \\S+$/", ["+ $error"]),
            // the client has to answer with an empty line - see testAuthenticateXoauth2AnswersContinuationWithEmptyLine
            self::step("/^(TAG\\d+ )?$/", ["TAG1 NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)"]),
        ]);

        $response = $protocol->authenticate("user@gmail.com", "invalid");

        self::assertTrue($response->failed());
        self::assertStringContainsString("Invalid credentials (Failure)", implode(" ", $response->getErrors()));
        $this->assertScriptCompleted();
    }

    public function testAuthenticateXoauth2AnswersContinuationWithEmptyLine(): void {
        $this->markTestSkipped("BUG: authenticate() answers a SASL continuation with \"TAGn \" (a new tag) instead of an empty line (RFC 3501 6.2.2 / RFC 4959)");

        $error = base64_encode('{"status":"400","schemes":"Bearer","scope":"https://mail.google.com/"}');
        $protocol = $this->connectedProtocol([
            self::step("/^TAG1 AUTHENTICATE XOAUTH2 \\S+$/", ["+ $error"]),
            self::step("/^$/", ["TAG1 NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)"]),
        ]);

        $protocol->authenticate("user@gmail.com", "invalid");
        $this->assertScriptCompleted();
    }

    public function testAuthenticateFailsWhenTheConnectionIsLost(): void {
        $protocol = $this->connectedProtocol([
            self::step("/^TAG1 AUTHENTICATE XOAUTH2 \\S+$/", [], ["close" => true]),
        ]);

        $this->expectException(AuthFailedException::class);
        $protocol->authenticate("user", "token");
    }

    /*
     * CAPABILITY / ID / LOGOUT / NOOP
     */

    public function testCapabilities(): void {
        $protocol = $this->connectedProtocol([
            self::step("CAPABILITY", [
                "* CAPABILITY IMAP4rev1 SASL-IR LOGIN-REFERRALS ID ENABLE IDLE SORT THREAD=REFERENCES MULTIAPPEND UNSELECT CHILDREN NAMESPACE UIDPLUS LIST-EXTENDED I18NLEVEL=1 CONDSTORE QRESYNC ESEARCH LITERAL+ MOVE QUOTA",
                "{tag} OK Capability completed (0.001 + 0.000 secs).",
            ]),
        ]);

        $capabilities = $protocol->getCapabilities()->validatedData();

        self::assertSame("CAPABILITY", $capabilities[0]);
        self::assertContains("IDLE", $capabilities);
        self::assertContains("MOVE", $capabilities);
        self::assertContains("THREAD=REFERENCES", $capabilities);
        self::assertNotContains("OK", $capabilities);
        $this->assertScriptCompleted();
    }

    public function testCapabilitiesFailure(): void {
        $protocol = $this->connectedProtocol([
            self::step("CAPABILITY", ["{tag} BAD Error in IMAP command CAPABILITY: Unknown command."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $protocol->getCapabilities();
    }

    public function testIdWithParameters(): void {
        $protocol = $this->connectedProtocol([
            self::step('ID ("name" "php-imap" "version" "6.2")', [
                '* ID ("name" "Dovecot")',
                "{tag} OK ID completed (0.001 + 0.000 secs).",
            ]),
        ]);

        $data = $protocol->ID(["name", "php-imap", "version", "6.2"])->validatedData();

        self::assertStringStartsWith('ID ("name" "Dovecot")', $data[0]);
        self::assertStringStartsWith("OK ID completed", $data[1]);
        $this->assertScriptCompleted();
    }

    public function testIdWithoutParametersSendsNil(): void {
        $protocol = $this->connectedProtocol([
            self::step("ID NIL", ['* ID ("name" "Dovecot")', "{tag} OK ID completed."]),
            self::step("ID NIL", ['* ID NIL', "{tag} OK ID completed."]),
        ]);

        $protocol->ID();
        $protocol->ID([]);
        $this->assertScriptCompleted();
    }

    public function testIdEscapesValues(): void {
        $this->markTestSkipped("BUG: ID() puts the values into quotes without escaping \" and \\ - the command becomes invalid");

        $protocol = $this->connectedProtocol([
            self::step('ID ("name" "my \\"client\\"")', ['* ID NIL', "{tag} OK ID completed."]),
        ]);

        $protocol->ID(["name", 'my "client"']);
        $this->assertScriptCompleted();
    }

    public function testNoop(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["* 22 EXPUNGE", "* 23 EXISTS", "* 3 RECENT", "* 14 FETCH (FLAGS (\\Seen \\Deleted))", "{tag} OK NOOP completed"]),
        ]);

        $data = $protocol->noop()->validatedData();

        self::assertSame([["22", "EXPUNGE"], ["23", "EXISTS"], ["3", "RECENT"], ["14", "FETCH", ["FLAGS", ["\\Seen", "\\Deleted"]]], ["OK", "NOOP", "completed"]], $data);
        $this->assertScriptCompleted();
    }

    public function testLogout(): void {
        $protocol = $this->connectedProtocol([
            self::step("LOGOUT", ["* BYE Logging out", "{tag} OK Logout completed."], ["close" => true]),
        ]);

        $response = $protocol->logout();

        self::assertSame(["* BYE Logging out\r\n", "TAG1 OK Logout completed.\r\n"], $response->getResponse());
        self::assertNull($protocol->getStream());
        self::assertFalse($protocol->connected());

        // a second logout does not send anything
        $second = $protocol->logout();
        self::assertSame([], $second->getCommands());
        $this->assertScriptCompleted();
        self::assertSame(["TAG1 LOGOUT"], $this->receivedLines(null, true));
    }

    public function testLogoutWhenServerAlreadyClosedTheConnection(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["* BYE Server shutting down."], ["close" => true]),
        ]);
        self::assertFalse($protocol->connected());

        $response = $protocol->logout();

        self::assertSame([], $response->getResponse());
        self::assertNull($protocol->getStream());
    }
}
