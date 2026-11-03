<?php
/*
* File: ServerMatrixTest.php
* Category: Test
* Created: 24.10.26
* Updated: -
*
* Description:
*  Smoke test against every IMAP target enabled via IMAP_TARGETS (see Tests\support\ImapTargets):
*  login (direct and through Toxiproxy), IDLE capability, folder create/delete, APPEND + fetch, and that
*  disabling the Toxiproxy proxy really cuts the connection. Skipped without IMAP_TARGETS.
*  Run with: .claude/scripts/imap-servers.sh test docker tests/live/ServerMatrixTest.php
*/

namespace Tests\live;

use PHPUnit\Framework\TestCase;
use Tests\support\ImapTargets;
use Tests\support\Toxiproxy;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Class ServerMatrixTest
 *
 * @package Tests\live
 */
class ServerMatrixTest extends TestCase {

    /**
     * Clients opened by the current test, disconnected in tearDown()
     * @var Client[] $clients
     */
    private array $clients = [];

    /**
     * Proxies disabled or poisoned by the current test, reset in tearDown()
     * @var bool $proxy_touched
     */
    private bool $proxy_touched = false;

    /**
     * Folders created by the current test, deleted in tearDown()
     * @var Folder[] $folders
     */
    private array $folders = [];

    /**
     * One data set per enabled target. Never empty and never throws: without IMAP_TARGETS (or with an
     * invalid value) a placeholder data set is returned and the test skips (or fails) itself.
     *
     * @return array
     */
    public function targetProvider(): array {
        try {
            $targets = ImapTargets::enabled();
        } catch (Throwable $e) {
            return ["invalid IMAP_TARGETS" => ["", $e->getMessage()]];
        }
        if (!$targets) {
            return ["no target" => ["", null]];
        }
        $data = [];
        foreach (array_keys($targets) as $name) {
            $data[$name] = [$name, null];
        }
        return $data;
    }

    /**
     * Disconnect clients, remove test folders and reset Toxiproxy
     *
     * @return void
     */
    protected function tearDown(): void {
        if ($this->proxy_touched) {
            try {
                (new Toxiproxy())->reset();
            } catch (Throwable) {
            }
        }
        foreach ($this->folders as $folder) {
            try {
                // Dovecot drops the connection (BYE) when the selected mailbox is deleted
                $folder->getClient()->openFolder("INBOX", true);
                $folder->delete(false);
            } catch (Throwable) {
            }
        }
        foreach ($this->clients as $client) {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // dead connection: drop the stream, otherwise Client::__destruct() runs into the same error
                (function() {
                    $this->connection?->reset();
                })->call($client);
            }
        }
        $this->clients = [];
        $this->folders = [];
        $this->proxy_touched = false;
    }

    /**
     * Login directly
     * @dataProvider targetProvider
     * @param string $target
     * @param string|null $error
     *
     * @return void
     * @throws Throwable
     */
    public function testLogin(string $target, ?string $error): void {
        $client = $this->connect($target, $error);
        self::assertTrue($client->isConnected());
    }

    /**
     * Login through the target's Toxiproxy proxy
     * @dataProvider targetProvider
     * @param string $target
     * @param string|null $error
     *
     * @return void
     * @throws Throwable
     */
    public function testLoginViaProxy(string $target, ?string $error): void {
        $client = $this->connect($target, $error, true);
        self::assertTrue($client->isConnected());
    }

    /**
     * The server announces IDLE (Folder::idle() depends on it)
     * @dataProvider targetProvider
     * @param string $target
     * @param string|null $error
     *
     * @return void
     * @throws Throwable
     */
    public function testCapabilitiesContainIdle(string $target, ?string $error): void {
        $client = $this->connect($target, $error);
        $capabilities = array_map("strtoupper", (array)$client->getConnection()->getCapabilities()->validatedData());

        self::assertContains("IMAP4REV1", $capabilities, "$target: " . implode(" ", $capabilities));
        self::assertContains("IDLE", $capabilities, "$target does not announce IDLE: " . implode(" ", $capabilities));
    }

    /**
     * Create a folder below INBOX, find it, delete it
     * @dataProvider targetProvider
     * @param string $target
     * @param string|null $error
     *
     * @return void
     * @throws Throwable
     */
    public function testCreateAndDeleteFolder(string $target, ?string $error): void {
        $client = $this->connect($target, $error);
        $delimiter = $this->delimiter($client, $target);
        $path = "INBOX" . $delimiter . "matrix-" . bin2hex(random_bytes(4));

        $folder = $client->createFolder($path, false);
        $this->folders[] = $folder;
        self::assertInstanceOf(Folder::class, $folder);

        $found = $client->getFolderByPath($path);
        self::assertInstanceOf(Folder::class, $found);
        self::assertSame($path, $found->path);

        $found->delete(false);
        $this->folders = [];
        self::assertNull($client->getFolderByPath($path, false, true));
    }

    /**
     * APPEND a message to a fresh folder and fetch it again
     * @dataProvider targetProvider
     * @param string $target
     * @param string|null $error
     *
     * @return void
     * @throws Throwable
     */
    public function testAppendAndFetch(string $target, ?string $error): void {
        $client = $this->connect($target, $error);
        $delimiter = $this->delimiter($client, $target);
        $folder = $client->createFolder("INBOX" . $delimiter . "matrix-" . bin2hex(random_bytes(4)), false);
        $this->folders[] = $folder;

        $subject = "Server matrix " . bin2hex(random_bytes(6));
        $raw = implode("\r\n", [
            "From: Sender <sender@example.local>",
            "To: Test <test@example.local>",
            "Subject: $subject",
            "Date: Sat, 24 Oct 2026 10:00:00 +0200",
            "Message-ID: <" . bin2hex(random_bytes(8)) . "@example.local>",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=utf-8",
            "",
            "Hello from the php-imap server matrix.",
            "",
        ]);
        $folder->appendMessage($raw);

        $messages = $folder->messages()->all()->get();
        self::assertCount(1, $messages);
        /** @var Message $message */
        $message = $messages->first();
        self::assertSame($subject, (string)$message->subject);
        self::assertSame("sender@example.local", $message->from->first()->mail);
        self::assertStringContainsString("Hello from the php-imap server matrix.", $message->getTextBody());
    }

    /**
     * Disabling the proxy closes an established connection: the next command fails instead of hanging
     * @dataProvider targetProvider
     * @param string $target
     * @param string|null $error
     *
     * @return void
     * @throws Throwable
     */
    public function testProxyDisableCutsConnection(string $target, ?string $error): void {
        $client = $this->connect($target, $error, true);
        $connection = $client->getConnection();
        $connection->noop()->validatedData();

        $this->proxy_touched = true;
        (new Toxiproxy())->disable(ImapTargets::get($target)["proxy"]["name"]);

        $start = microtime(true);
        $failed = false;
        try {
            $connection->noop()->validatedData();
        } catch (Throwable) {
            $failed = true;
        }
        self::assertTrue($failed, "NOOP still succeeded after the proxy was disabled");
        self::assertLessThan(5, microtime(true) - $start, "detecting the closed connection took too long");
    }

    /**
     * Connect to a target, skipping when no target is enabled or the proxy is not available
     * @param string $target
     * @param string|null $error
     * @param bool $via_proxy
     *
     * @return Client
     * @throws Throwable
     */
    private function connect(string $target, ?string $error, bool $via_proxy = false): Client {
        if ($error !== null) {
            self::fail($error);
        }
        if ($target === "") {
            self::markTestSkipped("No IMAP target enabled. Set IMAP_TARGETS (e.g. via .claude/scripts/imap-servers.sh test docker).");
        }
        if ($via_proxy) {
            if (empty(ImapTargets::get($target)["proxy"])) {
                self::markTestSkipped("IMAP target $target has no Toxiproxy proxy.");
            }
            if (!Toxiproxy::available()) {
                self::markTestSkipped("Toxiproxy API not reachable (TOXIPROXY_API).");
            }
            (new Toxiproxy())->reset();
        }

        $manager = new ClientManager([
            "options"  => ["debug" => false],
            "accounts" => ["default" => ImapTargets::account($target, $via_proxy) + ["timeout" => 10]],
        ]);
        $client = $manager->account("default");
        $this->clients[] = $client;
        $client->connect();

        return $client;
    }

    /**
     * Get the hierarchy delimiter announced by the server; checks it against the target's "delimiter"
     * @param Client $client
     * @param string $target
     *
     * @return string
     * @throws Throwable
     */
    private function delimiter(Client $client, string $target): string {
        $inbox = $client->getFolderByPath("INBOX");
        self::assertInstanceOf(Folder::class, $inbox);
        $expected = ImapTargets::get($target)["delimiter"];
        if ($expected !== null) {
            self::assertSame($expected, $inbox->delimiter, "delimiter of $target differs from targets.php");
        }
        return $inbox->delimiter;
    }
}
