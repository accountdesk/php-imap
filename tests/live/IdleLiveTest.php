<?php
/*
* File: IdleLiveTest.php
* Category: -
*
* Description:
*  Folder::idle() against real IMAP servers (Docker matrix in tests/servers, later also provider
*  accounts from tests/servers/targets.local.php). Enable targets with IMAP_TARGETS, e.g.
*  IMAP_TARGETS=docker or IMAP_TARGETS=dovecot23,greenmail (see .claude/scripts/imap-servers.sh).
*  Each test idles on its own new folder while support/idle_injector.php changes the folder on a
*  second connection and - for targets behind Toxiproxy - cuts or blackholes the network.
*  Every new message must be delivered exactly once, in order, and nothing else.
*  IDLE_SOAK_MINUTES=n additionally runs a long idle with the real keep-alive interval.
*/

namespace Tests\live;

use PHPUnit\Framework\TestCase;
use Tests\support\ImapTargets;
use Tests\support\Toxiproxy;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

class IdleLiveTest extends TestCase {

    protected ?Client $client = null;

    protected ?string $folder_path = null;

    /** @var resource|null */
    protected $injector = null;

    protected string $job_file = "";

    protected string $log_file = "";

    protected string $ready_file = "";

    /**
     * Targets enabled via IMAP_TARGETS - a placeholder keeps the data provider from being empty
     *
     * @return array<string, array{0: string}>
     */
    public static function targets(): array {
        $targets = [];
        foreach (array_keys(ImapTargets::enabled()) as $name) {
            $targets[$name] = [$name];
        }
        return $targets ?: ["none" => [""]];
    }

    protected function tearDown(): void {
        if (is_resource($this->injector)) {
            proc_terminate($this->injector);
            proc_close($this->injector);
        }
        if (Toxiproxy::available()) {
            (new Toxiproxy())->reset();
        }
        if ($this->client !== null && $this->folder_path !== null) {
            try {
                $this->client->checkConnection();
                $this->client->getFolderByPath($this->folder_path)?->delete();
            } catch (\Throwable) {
                // best effort - the folder name is unique
            }
        }
        if ($this->client !== null) {
            // PHPUnit keeps the test objects until the end of the run - an open connection per test would
            // exceed per-user connection limits (Dovecot: mail_max_userip_connections = 10)
            try {
                $this->client->disconnect();
            } catch (\Throwable) {
            }
            $this->client = null;
        }
        foreach ([$this->job_file, $this->log_file, $this->ready_file] as $file) {
            if ($file !== "" && file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Connect to the target and create a fresh folder for the test
     * @param string $target
     * @param bool $via_proxy
     * @param array<int, string> $initial subjects of messages that exist before idle() starts
     *
     * @return Folder
     */
    protected function prepare(string $target, bool $via_proxy = false, array $initial = []): Folder {
        if ($target === "") {
            $this->markTestSkipped("No IMAP target enabled. Set IMAP_TARGETS (e.g. \"docker\") to run the live idle tests.");
        }
        if ($via_proxy && (ImapTargets::get($target)["proxy"] ?? null) === null) {
            $this->markTestSkipped("Target $target is not reachable through Toxiproxy.");
        }
        if ($via_proxy && !Toxiproxy::available()) {
            $this->markTestSkipped("Toxiproxy is not available.");
        }

        $manager = new ClientManager([
            "options" => ["sequence" => IMAP::ST_UID, "debug" => (bool)getenv("LIVE_MAILBOX_DEBUG")],
            "accounts" => ["idle" => ImapTargets::account($target, $via_proxy)],
        ]);
        $this->client = $manager->account("idle");
        $this->client->connect();

        $inbox = $this->client->getFolderByPath("INBOX");
        self::assertNotNull($inbox, "INBOX not found on $target");
        $this->folder_path = "INBOX" . $inbox->delimiter . "php-imap-idle-" . bin2hex(random_bytes(4));
        $folder = $this->client->createFolder($this->folder_path, false);
        self::assertInstanceOf(Folder::class, $folder);

        foreach ($initial as $subject) {
            $folder->appendMessage(self::rawMessage($subject));
        }
        return $folder;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    protected static function rawMessage(string $subject): string {
        return "From: setup@example.com\r\nTo: idle@example.com\r\nSubject: $subject\r\n"
            . "Date: " . date("r") . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@php-imap.test>\r\n\r\nsetup\r\n";
    }

    /**
     * Start the injector with a timeline (see support/idle_injector.php) and wait until it is logged in - the
     * timeline starts then, right before the test calls idle(). It always works on a direct connection, so
     * network faults only hit the idle client.
     * @param string $target
     * @param array $actions
     */
    protected function startInjector(string $target, array $actions): void {
        $this->job_file = tempnam(sys_get_temp_dir(), "php-imap-injector-job-");
        $this->log_file = tempnam(sys_get_temp_dir(), "php-imap-injector-log-");
        $this->ready_file = tempnam(sys_get_temp_dir(), "php-imap-injector-ready-");
        file_put_contents($this->job_file, json_encode([
            "account"    => ImapTargets::account($target),
            "folder"     => $this->folder_path,
            "toxiproxy"  => Toxiproxy::available() ? (getenv("TOXIPROXY_API") ?: null) : null,
            "proxy"      => ImapTargets::get($target)["proxy"]["name"] ?? null,
            "ready_file" => $this->ready_file,
            "actions"    => $actions,
        ]));
        $command = [PHP_BINARY, dirname(__DIR__) . "/support/idle_injector.php", $this->job_file, $this->log_file];
        $this->injector = proc_open($command, [1 => ["file", "php://stderr", "w"], 2 => ["file", "php://stderr", "w"]], $pipes);
        self::assertIsResource($this->injector, "injector could not be started");

        $deadline = microtime(true) + 30;
        while (file_get_contents($this->ready_file) !== "ready") {
            if (!proc_get_status($this->injector)["running"] || microtime(true) > $deadline) {
                self::fail("injector did not get ready: " . file_get_contents($this->log_file));
            }
            usleep(20000);
        }
    }

    /**
     * Actions the injector executed - all must have succeeded, otherwise the test proves nothing
     *
     * @return array<int, array>
     */
    protected function injectorLog(): array {
        if (is_resource($this->injector)) {
            // wait for the remaining actions (the idle runtime is chosen to cover the timeline)
            $deadline = microtime(true) + 15;
            while (proc_get_status($this->injector)["running"] && microtime(true) < $deadline) {
                usleep(100000);
            }
        }
        $entries = array_map(fn($line) => json_decode($line, true), file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        foreach ($entries as $entry) {
            self::assertTrue($entry["ok"], "injector action {$entry["do"]} failed: " . ($entry["error"] ?? ""));
        }
        return $entries;
    }

    /**
     * Run idle() and collect what it delivers
     * @param Folder $folder
     * @param int $timeout keep-alive interval
     * @param int $max_runtime
     *
     * @return array{subjects: array<int, string>, stats: array<string, int>, reasons: array<int, string>}
     */
    protected function idle(Folder $folder, int $timeout, int $max_runtime): array {
        $subjects = [];
        $reasons = [];
        $stats = $folder->idle(function (Message $message) use (&$subjects) {
            $subjects[] = (string)$message->getSubject();
        }, $timeout, $max_runtime, function (string $reason) use (&$reasons) {
            $reasons[] = $reason;
        });
        return ["subjects" => $subjects, "stats" => $stats, "reasons" => $reasons];
    }

    /**
     * @dataProvider targets
     */
    public function testNewMessageIsDelivered(string $target): void {
        $folder = $this->prepare($target);
        $this->startInjector($target, [["at" => 2.0, "do" => "append", "subject" => "single"]]);

        $result = $this->idle($folder, 30, 6);
        $this->injectorLog();

        self::assertSame(["single"], $result["subjects"]);
        self::assertSame(0, $result["stats"]["reconnects"]);
    }

    /**
     * @dataProvider targets
     */
    public function testOnlyNewMessagesOfANonEmptyFolderAreDelivered(string $target): void {
        $folder = $this->prepare($target, false, ["old 1", "old 2", "old 3"]);
        $this->startInjector($target, [["at" => 2.0, "do" => "append", "subject" => "new"]]);

        $result = $this->idle($folder, 30, 6);
        $this->injectorLog();

        self::assertSame(["new"], $result["subjects"]);
    }

    /**
     * @dataProvider targets
     */
    public function testBurstIsDeliveredOnceAndInOrder(string $target): void {
        $folder = $this->prepare($target);
        $actions = [];
        for ($i = 1; $i <= 8; $i++) {
            $actions[] = ["at" => 2.0, "do" => "append", "subject" => "burst $i"];
        }
        $this->startInjector($target, $actions);

        $result = $this->idle($folder, 30, 9);
        $this->injectorLog();

        self::assertSame(array_column($actions, "subject"), $result["subjects"]);
    }

    /**
     * @dataProvider targets
     */
    public function testMessagesAcrossKeepAlivesAreDelivered(string $target): void {
        $folder = $this->prepare($target);
        $actions = [];
        foreach ([1.5, 2.6, 3.9, 5.0, 6.2, 7.5] as $i => $at) {
            $actions[] = ["at" => $at, "do" => "append", "subject" => "keepalive " . ($i + 1)];
        }
        $this->startInjector($target, $actions);

        $result = $this->idle($folder, 2, 10);
        $this->injectorLog();

        self::assertSame(array_column($actions, "subject"), $result["subjects"]);
        self::assertGreaterThanOrEqual(3, $result["stats"]["keepalives"]);
        self::assertSame(0, $result["stats"]["reconnects"], "keep-alives must not lead to reconnects");
    }

    /**
     * @dataProvider targets
     */
    public function testDeleteAndFlagChangesAreNotDelivered(string $target): void {
        $folder = $this->prepare($target, false, ["old 1", "old 2"]);
        $this->startInjector($target, [
            ["at" => 1.5, "do" => "flag", "subject" => "old 2", "flag" => "Seen"],
            ["at" => 2.5, "do" => "delete", "subject" => "old 1"],
            ["at" => 3.5, "do" => "append", "subject" => "after delete"],
            ["at" => 4.5, "do" => "delete", "subject" => "old 2"],
            ["at" => 5.5, "do" => "append", "subject" => "after second delete"],
        ]);

        $result = $this->idle($folder, 30, 8);
        $this->injectorLog();

        self::assertSame(["after delete", "after second delete"], $result["subjects"]);
    }

    /**
     * @dataProvider targets
     */
    public function testConnectionCutIsRecoveredWithoutLosingMessages(string $target): void {
        $folder = $this->prepare($target, true);
        $this->startInjector($target, [
            ["at" => 1.5, "do" => "append", "subject" => "before cut"],
            ["at" => 3.0, "do" => "proxy-down"],
            ["at" => 3.5, "do" => "append", "subject" => "while down"],
            ["at" => 4.0, "do" => "proxy-up"],
            ["at" => 7.0, "do" => "append", "subject" => "after reconnect"],
        ]);

        $result = $this->idle($folder, 2, 10);
        $this->injectorLog();

        self::assertSame(["before cut", "while down", "after reconnect"], $result["subjects"]);
        self::assertGreaterThanOrEqual(1, $result["stats"]["reconnects"]);
        self::assertContains("reconnect", $result["reasons"]);
    }

    /**
     * @dataProvider targets
     */
    public function testSilentlyDeadConnectionIsRecoveredWithoutLosingMessages(string $target): void {
        // Data is dropped but the connection stays open (NAT gateway forgot it): only the unanswered
        // keep-alive reveals it. The blackhole is lifted before idle() reconnects (keep-alive at ~2-4 s,
        // DONE times out after 2 s).
        $folder = $this->prepare($target, true);
        $this->startInjector($target, [
            ["at" => 1.0, "do" => "append", "subject" => "before blackhole"],
            ["at" => 2.2, "do" => "blackhole"],
            ["at" => 3.0, "do" => "append", "subject" => "during blackhole"],
            ["at" => 4.6, "do" => "blackhole-off"],
            ["at" => 9.0, "do" => "append", "subject" => "after recovery"],
        ]);

        $result = $this->idle($folder, 2, 12);
        $this->injectorLog();

        self::assertSame(["before blackhole", "during blackhole", "after recovery"], $result["subjects"]);
        self::assertGreaterThanOrEqual(1, $result["stats"]["reconnects"]);
    }

    /**
     * @dataProvider targets
     */
    public function testMaxRuntimeIsHonouredAndTheClientStaysUsable(string $target): void {
        $folder = $this->prepare($target);

        $start = microtime(true);
        $result = $this->idle($folder, 60, 3);
        $elapsed = microtime(true) - $start;

        self::assertLessThan(6, $elapsed, "idle() ran past max_runtime");
        self::assertSame(0, $result["stats"]["messages"]);
        // the regular connection still works after idle()
        $folder->appendMessage(self::rawMessage("after idle"));
        self::assertSame(1, $folder->query()->all()->count());
    }

    /**
     * Long run with a realistic keep-alive interval - opt-in, e.g. IDLE_SOAK_MINUTES=35 to cover the
     * 30 minute autologout of rfc3501 servers and typical NAT timeouts
     * @dataProvider targets
     */
    public function testSoak(string $target): void {
        $minutes = (int)getenv("IDLE_SOAK_MINUTES");
        if ($minutes <= 0) {
            $this->markTestSkipped("Set IDLE_SOAK_MINUTES to run the long idle test.");
        }
        $folder = $this->prepare($target);
        $actions = [];
        for ($at = 30, $i = 1; $at < $minutes * 60 - 30; $at += 170, $i++) {
            $actions[] = ["at" => (float)$at, "do" => "append", "subject" => "soak $i"];
        }
        $this->startInjector($target, $actions);

        $result = $this->idle($folder, (int)(getenv("IDLE_SOAK_TIMEOUT") ?: 300), $minutes * 60);
        $this->injectorLog();

        self::assertSame(array_column($actions, "subject"), $result["subjects"]);
    }
}
