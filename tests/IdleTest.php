<?php
/*
* File: IdleTest.php
* Category: -
*
* Description:
*  Folder::idle() against the scriptable fake server (support/idle_server.php).
*  Folder::idle() must not send any command other than DONE while the session is in IDLE.
*  rfc2177 compliant servers (e.g. Dovecot) answer "BAD Expected DONE" and terminate the
*  IDLE - new messages would then never be reported. Client::getConnection() verifies the
*  session with a NOOP, so it must not be called inside the idle loop.
*  Every new message must be delivered exactly once - however the server reports it (EXISTS while
*  idling, during DONE/IDLE, repeated, after an EXPUNGE, only via the next SELECT after a reconnect)
*  and whatever happens to the connection (closed, BYE, silently dead).
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\NotSupportedCapabilityException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

class IdleTest extends TestCase {

    /** @var resource|null */
    protected $server = null;

    protected string $log_file = "";

    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->log_file !== "" && file_exists($this->log_file)) {
            unlink($this->log_file);
        }
    }

    /**
     * Start the fake IMAP server and return its port
     * @param int $lifetime seconds until the server shuts down
     * @param string|array $scenario preset name or scenario definition, see support/idle_server.php
     *
     * @return int
     */
    protected function startServer(int $lifetime, string|array $scenario = ""): int {
        $this->log_file = tempnam(sys_get_temp_dir(), "php-imap-idle-");
        $scenario = is_array($scenario) ? json_encode($scenario) : $scenario;
        $command = [PHP_BINARY, __DIR__ . "/support/idle_server.php", $this->log_file, (string)$lifetime, $scenario];
        $this->server = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        self::assertIsResource($this->server, "fake IMAP server could not be started");
        $port = (int)trim((string)fgets($pipes[1]));
        self::assertGreaterThan(0, $port, "fake IMAP server did not report its port");
        return $port;
    }

    /**
     * Commands received by the fake server (connection 0 = server side events)
     *
     * @return array<int, array{connection: int, idling: bool, line: string}>
     */
    protected function receivedCommands(): array {
        $commands = [];
        foreach (file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
            [$connection, $idling, $line] = explode("\t", $row, 3);
            $commands[] = ["connection" => (int)$connection, "idling" => $idling === "1", "line" => $line];
        }
        return $commands;
    }

    /**
     * Connect a client to the fake server
     * @param int $port
     * @param int $sequence
     *
     * @return Folder
     */
    protected function connectFolder(int $port, int $sequence = IMAP::ST_UID): Folder {
        $manager = new ClientManager([
            "options" => ["sequence" => $sequence],
            "accounts" => [
                "default" => [
                    "host"          => "127.0.0.1",
                    "port"          => $port,
                    "encryption"    => false,
                    "validate_cert" => false,
                    "username"      => "user",
                    "password"      => "secret",
                    "protocol"      => "imap",
                ],
            ],
        ]);
        $client = $manager->account("default");
        $client->connect();
        return new Folder($client, "INBOX", "/", []);
    }

    /**
     * Run idle() against the fake server
     * @param string|array $scenario preset name or scenario definition, see support/idle_server.php
     * @param int $timeout keep-alive interval
     * @param int $max_runtime
     * @param callable|null $on_message additional callback function(Message $message, Folder $folder)
     *
     * @return array{subjects: array<int, string>, stats: array<string, int>, reasons: array<int, string>, commands: array}
     */
    protected function runIdle(string|array $scenario, int $timeout = 1, int $max_runtime = 3, ?callable $on_message = null): array {
        $port = $this->startServer($max_runtime + 15, $scenario);
        $folder = $this->connectFolder($port);

        $subjects = [];
        $reasons = [];
        $stats = $folder->idle(function (Message $message) use (&$subjects, $on_message, $folder) {
            $subjects[] = (string)$message->getSubject();
            if ($on_message !== null) {
                $on_message($message, $folder);
            }
        }, $timeout, $max_runtime, function (string $reason) use (&$reasons) {
            $reasons[] = $reason;
        });

        return ["subjects" => $subjects, "stats" => $stats, "reasons" => $reasons, "commands" => $this->receivedCommands()];
    }

    /**
     * Assert that no command other than DONE was sent while a session was in IDLE
     * @param array $commands
     */
    protected static function assertNoCommandsDuringIdle(array $commands): void {
        $during_idle = array_values(array_filter($commands, fn($c) => $c["idling"] && strtoupper($c["line"]) !== "DONE"));
        self::assertSame([], $during_idle, "commands sent while the session was in IDLE");
    }

    /**
     * @param array $commands
     * @param string $command e.g. "IDLE", "DONE", "LOGIN"
     *
     * @return int number of times the command was sent
     */
    protected static function countCommand(array $commands, string $command): int {
        return count(array_filter($commands, function ($c) use ($command) {
            $line = strtoupper($c["line"]);
            return $c["connection"] > 0 && ($line === $command || str_contains($line, " $command"));
        }));
    }

    public function testIdleDoesNotSendCommandsDuringIdle(): void {
        $port = $this->startServer(5);
        $folder = $this->connectFolder($port);

        try {
            $folder->idle(function () {}, 1, 3);
        } catch (\Throwable) {
            // the server went away - only the recorded commands matter
        }

        $commands = $this->receivedCommands();
        self::assertNotEmpty($commands);
        self::assertNoCommandsDuringIdle($commands);

        $first_idle = null;
        foreach ($commands as $index => $command) {
            if (!$command["idling"] && str_ends_with(strtoupper($command["line"]), " IDLE")) {
                $first_idle = $index;
                break;
            }
        }
        self::assertNotNull($first_idle, "IDLE was never issued");

        // A login after IDLE was issued means the idle session was silently replaced
        $later_logins = array_filter(array_slice($commands, $first_idle), fn($c) => str_contains(strtoupper($c["line"]), " LOGIN "));
        self::assertSame([], array_values($later_logins), "the idle session was silently reconnected");
    }

    public function testDeliversMessageAnnouncedDuringIdle(): void {
        $result = $this->runIdle(["events" => [["at" => 0.3, "do" => "arrive"]]]);

        self::assertSame(["message 1"], $result["subjects"]);
        self::assertSame(1, $result["stats"]["messages"]);
        self::assertSame(0, $result["stats"]["reconnects"]);
        self::assertNoCommandsDuringIdle($result["commands"]);
    }

    public function testDeliversOnlyNewMessagesOfANonEmptyMailbox(): void {
        $result = $this->runIdle(["initial" => 5, "events" => [["at" => 0.3, "do" => "arrive"]]]);

        self::assertSame(["message 6"], $result["subjects"]);
    }

    public function testDeliversAllMessagesReportedByOneExists(): void {
        $result = $this->runIdle(["initial" => 2, "events" => [["at" => 0.3, "do" => "arrive", "count" => 3]]]);

        self::assertSame(["message 3", "message 4", "message 5"], $result["subjects"]);
        self::assertSame(3, $result["stats"]["messages"]);
    }

    public function testDeliversMessagesArrivingInQuickSuccession(): void {
        $result = $this->runIdle(["events" => [
            ["at" => 0.3, "do" => "arrive"],
            ["at" => 0.3, "do" => "arrive"],
            ["at" => 0.4, "do" => "arrive"],
            ["at" => 1.5, "do" => "arrive"],
        ]]);

        self::assertSame(["message 1", "message 2", "message 3", "message 4"], $result["subjects"]);
    }

    public function testDeliversMessagesOverSeveralKeepAlives(): void {
        $result = $this->runIdle(["events" => [
            ["at" => 0.2, "do" => "arrive"],
            ["at" => 1.1, "do" => "arrive"],
            ["at" => 2.3, "do" => "arrive"],
            ["at" => 3.5, "do" => "arrive"],
        ]], 1, 5);

        self::assertSame(["message 1", "message 2", "message 3", "message 4"], $result["subjects"]);
        self::assertGreaterThanOrEqual(3, $result["stats"]["keepalives"]);
        self::assertNoCommandsDuringIdle($result["commands"]);
    }

    public function testRepeatedExistsIsNotDeliveredAgain(): void {
        $result = $this->runIdle(["events" => [
            ["at" => 0.3, "do" => "arrive"],
            ["at" => 0.6, "do" => "send", "line" => "* 1 EXISTS"],
            ["at" => 0.9, "do" => "send", "line" => "* 1 EXISTS"],
        ]]);

        self::assertSame(["message 1"], $result["subjects"]);
    }

    public function testExistsLowerThanTheKnownSizeIsNotANewMessage(): void {
        $result = $this->runIdle(["initial" => 3, "events" => [["at" => 0.3, "do" => "send", "line" => "* 2 EXISTS"]]]);

        self::assertSame([], $result["subjects"]);
    }

    public function testExpungeOnlyDeliversNothing(): void {
        $result = $this->runIdle(["initial" => 2, "events" => [["at" => 0.3, "do" => "expunge", "msgn" => 1]]]);

        self::assertSame([], $result["subjects"]);
    }

    public function testArrivalAfterExpungeIsDeliveredAlthoughTheSizeIsUnchanged(): void {
        // 1 message, it gets deleted and a new one arrives: EXISTS reports 1 again
        $result = $this->runIdle(["initial" => 1, "events" => [
            ["at" => 0.3, "do" => "expunge", "msgn" => 1],
            ["at" => 0.5, "do" => "arrive"],
        ]]);

        self::assertSame(["message 2"], $result["subjects"]);
    }

    public function testUnrelatedUntaggedResponsesAreIgnored(): void {
        $result = $this->runIdle(["initial" => 2, "events" => [
            ["at" => 0.2, "do" => "flags", "msgn" => 1, "flags" => "\\Seen"],
            ["at" => 0.3, "do" => "send", "line" => "* 1 RECENT"],
            ["at" => 0.4, "do" => "send", "line" => "* OK [ALERT] Mailbox almost full"],
            ["at" => 0.5, "do" => "send", "line" => "* NO [OVERQUOTA] Quota exceeded"],
            ["at" => 0.6, "do" => "send", "line" => "* 2 FETCH (UID 2 FLAGS (\\Seen \\Flagged))"],
            ["at" => 0.8, "do" => "arrive", "recent" => true],
        ]]);

        self::assertSame(["message 3"], $result["subjects"]);
        self::assertSame(0, $result["stats"]["reconnects"]);
    }

    public function testExistsSentDuringDoneIsDelivered(): void {
        $result = $this->runIdle("exists-on-done");

        self::assertSame(["message 1"], $result["subjects"]);
        self::assertSame(1, $result["stats"]["messages"]);
    }

    public function testServerWithoutPushReportsOnKeepAlive(): void {
        // Like Cyrus without idled: changes are only reported when a command completes
        $result = $this->runIdle(["push" => false, "events" => [
            ["at" => 0.2, "do" => "arrive"],
            ["at" => 1.3, "do" => "arrive", "count" => 2],
        ]], 1, 4);

        self::assertSame(["message 1", "message 2", "message 3"], $result["subjects"]);
    }

    public function testExistsSentBeforeTheIdleContinuationIsDelivered(): void {
        $result = $this->runIdle(["before_continuation" => true, "push" => false, "events" => [
            ["at" => 0.2, "do" => "arrive"],
            ["on_done" => 1, "do" => "arrive"],
        ]], 1, 3);

        self::assertSame(["message 1", "message 2"], $result["subjects"]);
    }

    public function testExistsSentWithTheFinalDoneIsDelivered(): void {
        // Keep-alive interval longer than the runtime: the only DONE is the one that ends idle()
        $result = $this->runIdle(["push" => false, "events" => [["at" => 0.5, "do" => "arrive"]]], 30, 2);

        self::assertSame(["message 1"], $result["subjects"]);
        self::assertSame(0, $result["stats"]["keepalives"]);
    }

    public function testGreenMailStyleRepeatedSizeOnEveryDoneIsNotDeliveredAgain(): void {
        $result = $this->runIdle(["repeat_exists_on_done" => true, "initial" => 1, "events" => [["at" => 0.3, "do" => "arrive"]]], 1, 4);

        self::assertSame(["message 2"], $result["subjects"]);
        self::assertGreaterThanOrEqual(2, $result["stats"]["keepalives"]);
    }

    public function testMessagesThatArrivedWhileReconnectingAreDelivered(): void {
        $result = $this->runIdle("lost-connection");

        self::assertGreaterThanOrEqual(1, $result["stats"]["reconnects"]);
        self::assertSame(["message 1"], $result["subjects"]);
        self::assertContains("reconnect", $result["reasons"]);
    }

    public function testMessagesThatArrivedWhileReconnectingAreDeliveredWithoutUidnextInSelect(): void {
        // Like Courier: UIDNEXT only via STATUS
        $result = $this->runIdle(["uidnext_in_select" => false, "initial" => 1, "events" => [
            ["at" => 0.5, "do" => "arrive", "silent" => true],
            ["at" => 0.5, "do" => "drop"],
            ["at" => 2.0, "do" => "arrive"],
        ]], 1, 4);

        self::assertSame(["message 2", "message 3"], $result["subjects"]);
        self::assertSame(1, $result["stats"]["reconnects"]);
    }

    public function testMessagesBeforeAndAfterAReconnectAreDeliveredOnce(): void {
        $result = $this->runIdle(["initial" => 1, "events" => [
            ["at" => 0.2, "do" => "arrive"],
            ["at" => 0.6, "do" => "arrive", "silent" => true],
            ["at" => 0.6, "do" => "drop"],
            ["at" => 2.0, "do" => "arrive"],
        ]], 1, 4);

        self::assertSame(["message 2", "message 3", "message 4"], $result["subjects"]);
        self::assertSame(1, $result["stats"]["reconnects"]);
    }

    public function testServerByeLeadsToReconnect(): void {
        $result = $this->runIdle(["events" => [
            ["at" => 0.3, "do" => "bye", "text" => "Server shutting down."],
            ["at" => 1.5, "do" => "arrive"],
        ]], 1, 4);

        self::assertGreaterThanOrEqual(1, $result["stats"]["reconnects"]);
        self::assertSame(["message 1"], $result["subjects"]);
    }

    public function testSilentlyDeadConnectionIsReplaced(): void {
        // The connection stops answering without being closed (NAT timeout): the keep-alive DONE gets no
        // answer, idle() has to notice it and reconnect
        $result = $this->runIdle(["events" => [
            ["at" => 0.2, "do" => "freeze"],
            ["at" => 0.5, "do" => "arrive", "silent" => true],
            ["at" => 4.0, "do" => "arrive"],
        ]], 1, 6);

        self::assertGreaterThanOrEqual(1, $result["stats"]["reconnects"]);
        self::assertSame(["message 1", "message 2"], $result["subjects"]);
    }

    public function testServerRestartIsSurvivedWithoutLosingMessages(): void {
        // The server is gone for 2.5 s - the first reconnect attempts fail. A message arrives meanwhile.
        $result = $this->runIdle(["events" => [
            ["at" => 0.2, "do" => "arrive"],
            ["at" => 0.6, "do" => "arrive", "silent" => true],
            ["at" => 0.6, "do" => "outage", "seconds" => 2.5],
            ["at" => 5.0, "do" => "arrive"],
        ]], 1, 7);

        self::assertSame(["message 1", "message 2", "message 3"], $result["subjects"]);
        self::assertSame(1, $result["stats"]["reconnects"]);
        self::assertSame(["reconnect"], array_values(array_unique(array_filter($result["reasons"], fn($r) => $r !== "keepalive"))));
    }

    public function testGivesUpWhenTheServerStaysUnreachable(): void {
        $port = $this->startServer(30, ["events" => [["at" => 0.3, "do" => "outage", "seconds" => 60]]]);
        $folder = $this->connectFolder($port);

        $start = microtime(true);
        try {
            $folder->idle(function () {}, 1, 4);
            self::fail("idle() must not return normally while the server is unreachable");
        } catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException) {
            // expected
        }
        self::assertLessThan(8, microtime(true) - $start, "the reconnect attempts must respect max_runtime");
    }

    public function testUidValidityChangeDoesNotDeliverOldMessagesAgain(): void {
        // The mailbox was recreated while the connection was down: UIDs start over
        $result = $this->runIdle(["initial" => 2, "events" => [
            ["at" => 0.2, "do" => "arrive"],
            ["at" => 0.5, "do" => "uidvalidity", "value" => 7],
            ["at" => 0.5, "do" => "drop"],
            ["at" => 2.0, "do" => "arrive"],
        ]], 1, 4);

        self::assertSame(["message 3", "message 4"], $result["subjects"]);
        self::assertSame(1, $result["stats"]["reconnects"]);
    }

    public function testDovecotStillHereDoesNotStretchTheKeepAliveInterval(): void {
        $result = $this->runIdle(["still_here" => 0.3], 1, 4);

        // A keep-alive at least every 1 s - without the shortened read timeout every "* OK Still here"
        // would restart the timer and no keep-alive would ever happen
        self::assertGreaterThanOrEqual(3, $result["stats"]["keepalives"]);
        self::assertNoCommandsDuringIdle($result["commands"]);
    }

    public function testKeepAliveCallbackAndStats(): void {
        $result = $this->runIdle([], 1, 3);

        self::assertGreaterThanOrEqual(2, $result["stats"]["keepalives"]);
        self::assertSame($result["stats"]["keepalives"], count($result["reasons"]));
        self::assertSame($result["stats"]["checks"], count($result["reasons"]));
        self::assertSame(["keepalive"], array_values(array_unique($result["reasons"])));
        self::assertSame(0, $result["stats"]["messages"]);
        self::assertSame(0, $result["stats"]["reconnects"]);
        // every keep-alive is DONE + IDLE on the same session
        self::assertSame($result["stats"]["keepalives"] + 1, self::countCommand($result["commands"], "IDLE"));
    }

    public function testMaxRuntimeEndsIdleGracefully(): void {
        $start = microtime(true);
        $result = $this->runIdle([], 30, 2);
        $elapsed = microtime(true) - $start;

        self::assertGreaterThanOrEqual(2, $result["stats"]["runtime"]);
        self::assertLessThan(4, $elapsed);
        $idle_session = array_values(array_filter($result["commands"], fn($c) => $c["connection"] > 0 && str_ends_with(strtoupper($c["line"]), " IDLE")))[0]["connection"];
        // the last IDLE of the session is ended with DONE (NOOP/LOGOUT may follow when the idle client is destroyed)
        $session = array_values(array_filter($result["commands"], fn($c) => $c["connection"] === $idle_session));
        $last_idle = max(array_keys(array_filter($session, fn($c) => str_ends_with(strtoupper($c["line"]), " IDLE"))));
        self::assertSame("DONE", strtoupper($session[$last_idle + 1]["line"] ?? ""), "idle() must end the IDLE command with DONE");
    }

    public function testCallbackMayUseTheFolderWhileIdling(): void {
        $result = $this->runIdle(["events" => [["at" => 0.3, "do" => "arrive"], ["at" => 1.5, "do" => "arrive"]]], 1, 3,
            function (Message $message, Folder $folder) {
                $message->setFlag("Seen");
                $folder->query()->all()->count();
            });

        self::assertSame(["message 1", "message 2"], $result["subjects"]);
        self::assertNoCommandsDuringIdle($result["commands"]);
        self::assertSame(0, $result["stats"]["reconnects"]);
    }

    public function testMessageNewEventIsDispatched(): void {
        $port = $this->startServer(8, ["events" => [["at" => 0.3, "do" => "arrive"]]]);
        $folder = $this->connectFolder($port);
        $events = [];
        $folder->getClient()->setEvent("message", "new", new class {
            public static array $received = [];
            public function __construct(?Message $message = null) {
                if ($message !== null) {
                    self::$received[] = (string)$message->getSubject();
                }
            }
        });

        $folder->idle(function (Message $message) use (&$events) {
            $events[] = (string)$message->getSubject();
        }, 1, 2);

        self::assertSame(["message 1"], $events);
    }

    public function testSequenceOptionIsAppliedToDeliveredMessages(): void {
        $port = $this->startServer(8, ["initial" => 3, "events" => [["at" => 0.3, "do" => "arrive"]]]);
        $folder = $this->connectFolder($port, IMAP::ST_MSGN);
        $received = [];

        $folder->idle(function (Message $message) use (&$received) {
            $received[] = [$message->getSequence(), $message->getSequenceId(), (int)$message->getUid()];
        }, 1, 2);

        self::assertSame([[IMAP::ST_MSGN, 4, 4]], $received);
    }

    public function testMoreThanFiftyNewMessagesAtOnceAreCappedToTheNewestFifty(): void {
        // Documents the current limit of idle(): a single EXISTS jump delivers at most 50 messages
        $result = $this->runIdle(["events" => [["at" => 0.3, "do" => "arrive", "count" => 60]]], 30, 3);

        self::assertCount(50, $result["subjects"]);
        self::assertSame("message 11", $result["subjects"][0]);
        self::assertSame("message 60", end($result["subjects"]));
    }

    public function testServerWithoutIdleCapabilityIsRejected(): void {
        $port = $this->startServer(5, ["capability" => "IMAP4rev1"]);
        $folder = $this->connectFolder($port);

        $this->expectException(NotSupportedCapabilityException::class);
        $folder->idle(function () {}, 1, 2);
    }

    public function testIdleRefusedByTheServerThrows(): void {
        $port = $this->startServer(5, ["idle_reply" => "NO IDLE is disabled for this account"]);
        $folder = $this->connectFolder($port);

        $this->expectException(RuntimeException::class);
        $folder->idle(function () {}, 1, 2);
    }
}
