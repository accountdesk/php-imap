<?php
/*
* File: ServerFailureTest.php
* Category: Test
*
* Description:
*  Broken and hostile server answers against the scripted fake IMAP server: truncated responses, wrong
*  literal sizes, foreign tags, huge literals, BYE in the middle of a command and NO / BAD / BYE at every
*  step of a login -> select -> search -> fetch flow. The library has to end with a clear exception of
*  its own - never hang, loop or raise a PHP warning.
*/

namespace Tests;

use Tests\support\ScriptedServerTestCase;
use Throwable;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

class ServerFailureTest extends ScriptedServerTestCase {

    /**
     * Run the code and return the exception it throws - failing for no exception, for a PHP error /
     * warning or for one that is not part of the library
     * @param callable $code
     * @param float $max_seconds
     *
     * @return Throwable
     */
    protected function assertFailsCleanly(callable $code, float $max_seconds = 4.0): Throwable {
        $start = microtime(true);
        try {
            $code();
        } catch (Throwable $e) {
            self::assertStringStartsWith("Webklex\\PHPIMAP\\Exceptions\\", get_class($e),
                "unexpected " . get_class($e) . ": " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            self::assertLessThan($max_seconds, microtime(true) - $start, "took too long");
            return $e;
        }
        self::fail("an exception was expected");
    }

    /*
     * Protocol level
     */

    public function testTruncatedResponseAndClosedConnection(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (FLAGS)", [["raw" => "* 1 FETCH (UID 5 FLAGS (\\Se"]], ["close" => true]),
        ]);

        $e = $this->assertFailsCleanly(fn() => $protocol->fetch("FLAGS", 5));
        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame("empty response", $e->getMessage());
    }

    /**
     * The server stops in the middle of a line and keeps the connection open: the read timeout ends it
     */
    public function testStalledResponseTimesOut(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (FLAGS)", [["raw" => "* 1 FETCH (UID 5 FLAGS (\\Se"]]),
        ]);
        $protocol->setConnectionTimeout(1);
        // the timeout applies to the stream created by connect()
        stream_set_timeout($protocol->getStream(), 1);

        $e = $this->assertFailsCleanly(fn() => $protocol->fetch("FLAGS", 5));
        self::assertInstanceOf(RuntimeException::class, $e);
    }

    /**
     * A literal announcing more bytes than sent swallows the tagged status - the read timeout ends it
     */
    public function testLiteralLongerThanItsData(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.HEADER)", ["* 1 FETCH (UID 5 RFC822.HEADER {500}", "Subject: short", ")", "{tag} OK Fetch completed."]),
        ]);
        stream_set_timeout($protocol->getStream(), 1);

        $this->assertFailsCleanly(fn() => $protocol->fetch("RFC822.HEADER", 5));
    }

    /**
     * A literal announcing fewer bytes than sent: the rest is parsed as further tokens, no error
     */
    public function testLiteralShorterThanItsData(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5,6 (RFC822.HEADER)", [
                "* 1 FETCH (UID 5 RFC822.HEADER {3}",
                "Subject: longer than three bytes)",
                "* 2 FETCH (UID 6 RFC822.HEADER {9}",
                "Subject: ok)",
                "{tag} OK Fetch completed.",
            ]),
        ]);

        $result = $protocol->fetch(["RFC822.HEADER"], [5, 6])->validatedData();
        self::assertSame("Sub", $result[5]);
        $this->assertScriptCompleted();
    }

    /**
     * A tagged line of another (e.g. an earlier, timed out) command is skipped, the own tag completes
     */
    public function testForeignTagIsSkipped(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["TAG99 OK stale answer", "{tag} OK NOOP completed."]),
        ]);

        self::assertTrue($protocol->noop()->successful());
        $this->assertScriptCompleted();
    }

    public function testOnlyAForeignTagTimesOut(): void {
        $protocol = $this->connectedProtocol([
            self::step("NOOP", ["TAG99 OK NOOP completed."]),
        ]);
        stream_set_timeout($protocol->getStream(), 1);

        $this->assertFailsCleanly(fn() => $protocol->noop());
    }

    public function testHugeLiteral(): void {
        $body = str_repeat("0123456789abcdef", 2 * 1024 * 1024 / 16 / 4) . "\r\n";
        $body = str_repeat($body, 4);
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.TEXT)", ["* 1 FETCH (UID 5 RFC822.TEXT " . self::literal($body) . ")", "{tag} OK Fetch completed."]),
        ]);

        $data = $protocol->fetch(["RFC822.TEXT"], [5])->validatedData();
        self::assertSame(strlen($body), strlen($data[5]));
        self::assertSame(md5($body), md5($data[5]));
        $this->assertScriptCompleted();
    }

    /**
     * A literal size beyond any real message, followed by a closed connection
     */
    public function testAbsurdLiteralSize(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.TEXT)", ["* 1 FETCH (UID 5 RFC822.TEXT {99999999999}", "short"], ["close" => true]),
        ]);

        $e = $this->assertFailsCleanly(fn() => $protocol->fetch(["RFC822.TEXT"], [5]));
        self::assertSame("empty response", $e->getMessage());
    }

    /**
     * RFC 3501 7.1.5: BYE and the server closes the connection - in the middle of a command
     */
    public function testByeInTheMiddleOfACommand(): void {
        $commands = [
            "fetch"  => [self::step("UID FETCH 5:5 (FLAGS)", ["* BYE Server shutting down"], ["close" => true]), fn($p) => $p->fetch("FLAGS", 5)],
            "select" => [self::step('SELECT "INBOX"', ["* 3 EXISTS", "* BYE Server shutting down"], ["close" => true]), fn($p) => $p->selectFolder("INBOX")],
            "search" => [self::step("UID SEARCH ALL", ["* BYE Server shutting down"], ["close" => true]), fn($p) => $p->search(["ALL"])],
            "login"  => [self::step('LOGIN "user" "secret"', ["* BYE Server shutting down"], ["close" => true]), fn($p) => $p->login("user", "secret")],
            "list"   => [self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" INBOX', "* BYE Server shutting down"], ["close" => true]), fn($p) => $p->folders()],
        ];
        foreach ($commands as $name => [$step, $call]) {
            $protocol = $this->connectedProtocol([$step]);
            $this->assertFailsCleanly(fn() => $call($protocol));
            $this->stopServer();
        }
    }

    /**
     * A server that closes the connection right after the greeting
     */
    public function testConnectionClosedAfterGreeting(): void {
        $port = $this->startServer([["close_after_greeting" => true]]);
        $protocol = $this->protocol();
        $protocol->connect("127.0.0.1", $port);

        $this->assertFailsCleanly(fn() => $protocol->login("user", "secret"));
        // the protocol reports the connection as lost instead of throwing
        self::assertFalse($protocol->connected());
    }

    public function testGreetingWithoutOk(): void {
        foreach (["* BYE Too many connections from your IP", "* PREAUTH-ish garbage", "HTTP/1.1 400 Bad Request"] as $greeting) {
            $port = $this->startServer([["greeting" => $greeting]]);
            $this->assertFailsCleanly(fn() => $this->protocol()->connect("127.0.0.1", $port));
            $this->stopServer();
        }
    }

    /*
     * Client: login -> select -> search -> fetch, broken at every step
     */

    /**
     * The complete conversation of Folder::query()->all()->get() for two messages
     *
     * @return array<int, array>
     */
    protected static function queryFlow(): array {
        $header = "From: Sender <sender@example.com>\r\nTo: user@example.com\r\nSubject: Hello\r\n"
            . "Date: Thu, 22 Oct 2026 10:00:00 +0000\r\nMessage-ID: <%d@example.com>\r\n\r\n";
        $h5 = sprintf($header, 5);
        $h6 = sprintf($header, 6);
        return [
            self::loginStep(),
            self::step('SELECT "INBOX"', ["* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)", "* 2 EXISTS", "* 0 RECENT", "* OK [UIDVALIDITY 1385478918] UIDs valid", "* OK [UIDNEXT 7] Predicted next UID", "{tag} OK [READ-WRITE] Select completed (0.001 + 0.000 secs)."]),
            self::step("UID SEARCH ALL", ["* SEARCH 5 6", "{tag} OK Search completed (0.001 + 0.000 secs)."]),
            self::step("UID FETCH 5,6 (FLAGS)", ["* 1 FETCH (UID 5 FLAGS (\\Seen))", "* 2 FETCH (UID 6 FLAGS ())", "{tag} OK Fetch completed (0.001 + 0.000 secs)."]),
            self::step("UID FETCH 5,6 (RFC822.HEADER)", [
                "* 1 FETCH (UID 5 RFC822.HEADER " . self::literal($h5) . ")",
                "* 2 FETCH (UID 6 RFC822.HEADER " . self::literal($h6) . ")",
                "{tag} OK Fetch completed (0.001 + 0.000 secs).",
            ]),
            self::step("UID FETCH 5,6 (BODY.PEEK[TEXT])", [
                "* 1 FETCH (UID 5 BODY[TEXT] " . self::literal("Body 5\r\n") . ")",
                "* 2 FETCH (UID 6 BODY[TEXT] " . self::literal("Body 6\r\n") . ")",
                "{tag} OK Fetch completed (0.001 + 0.000 secs).",
            ]),
        ];
    }

    /**
     * Run the query flow against the given steps
     * @param array $steps
     *
     * @return mixed
     */
    protected function runQueryFlow(array $steps): mixed {
        $port = $this->startServer([["steps" => $steps, "auto" => [self::noopStep()]]]);
        $client = $this->client($port);
        $client->connect();
        $folder = new Folder($client, "INBOX", "/", []);
        return $folder->query()->all()->get();
    }

    public function testQueryFlow(): void {
        $messages = $this->runQueryFlow(self::queryFlow());

        self::assertSame(2, $messages->count());
        self::assertSame("Hello", (string)$messages->first()->subject);
        self::assertSame("Body 6", $messages->last()->getTextBody());
        $this->assertScriptCompleted();
    }

    /**
     * @return array<string, array{int, array}>
     */
    public static function brokenFlowProvider(): array {
        $names = ["login", "select", "search", "flags", "header", "body"];
        $answers = [
            "NO"  => ["{tag} NO [UNAVAILABLE] Temporary failure, try again later (0.001 + 0.000 secs)."],
            "BAD" => ["{tag} BAD Error in IMAP command: Unknown argument (0.001 + 0.000 secs)."],
            "BYE" => ["* BYE Server shutting down."],
            "cut" => [["raw" => "* 1 FETCH (UID 5 "]],
        ];
        $cases = [];
        foreach ($names as $index => $name) {
            foreach ($answers as $answer => $reply) {
                $cases["$answer at $name"] = [$index, $reply, $answer === "BYE" || $answer === "cut"];
            }
        }
        return $cases;
    }

    /**
     * @dataProvider brokenFlowProvider
     */
    public function testBrokenQueryFlow(int $index, array $reply, bool $close): void {
        $steps = array_slice(self::queryFlow(), 0, $index + 1);
        $steps[$index]["reply"] = $reply;
        $steps[$index]["close"] = $close;

        $this->assertFailsCleanly(fn() => $this->runQueryFlow($steps), 8.0);
    }

    /**
     * A failed SELECT leaves no mailbox selected (RFC 3501 6.3.1) - the client must not believe it is in
     * the folder and select it again on the next attempt
     */
    public function testFailedSelectIsRetried(): void {
        $port = $this->startServer([[
            "steps" => [
                self::loginStep(),
                self::step('SELECT "Archive"', ["{tag} NO [SERVERBUG] Internal error occurred."]),
                self::step('SELECT "Archive"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            ],
            "auto"  => [self::noopStep()],
        ]]);
        $client = $this->client($port)->connect();

        $this->assertFailsCleanly(fn() => $client->openFolder("Archive"));
        self::assertNull($client->getActiveFolder());
        self::assertSame(["exists" => 0], $client->openFolder("Archive"));
        self::assertSame("Archive", $client->getActiveFolder());
        $this->assertScriptCompleted();
    }

    /**
     * UID FETCH answered with message number FETCH lines only (UID missing): no result, no warning
     */
    public function testFetchWithoutUidInTheAnswer(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5,6 (FLAGS)", ["* 1 FETCH (FLAGS (\\Seen))", "* 2 FETCH (FLAGS ())", "{tag} OK Fetch completed."]),
        ]);

        self::assertSame([], $protocol->fetch(["FLAGS"], [5, 6], null, IMAP::ST_UID)->data());
        $this->assertScriptCompleted();
    }
}
