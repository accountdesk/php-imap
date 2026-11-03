<?php
/*
* File: ScriptedServerTestCase.php
* Category: Test support
*
* Description:
*  Base class for tests that talk to the scripted fake IMAP server (support/scripted_imap_server.php).
*  A test describes the conversation as a list of steps (expected client line => server reply lines),
*  starts the server, runs the client code and checks with assertScriptCompleted() that every step was
*  received in order and nothing unexpected was sent.
*/

namespace Tests\support;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

abstract class ScriptedServerTestCase extends TestCase {

    /** @var resource|null fake server process */
    protected $server = null;

    /** @var array<int, resource> pipes of the fake server process */
    protected array $pipes = [];

    protected ?string $script_file = null;
    protected ?string $log_file = null;

    /** @var array<int, array> connection scripts of the running server */
    protected array $connections = [];

    /** @var array<int, object> protocols / clients created by the test - closed before the server stops */
    protected array $created = [];

    /** @var array<int, string> temporary files */
    protected array $temp_files = [];

    /**
     * Default greeting (Dovecot)
     */
    const GREETING = "* OK [CAPABILITY IMAP4rev1 SASL-IR LOGIN-REFERRALS ID ENABLE IDLE LITERAL+ AUTH=PLAIN] Dovecot ready.";

    protected function tearDown(): void {
        // close connections first: destructors send LOGOUT, which the server answers
        foreach ($this->created as $object) {
            try {
                if ($object instanceof Client) {
                    $object->disconnect();
                } elseif ($object instanceof ImapProtocol) {
                    $object->logout();
                }
            } catch (\Throwable) {
            }
        }
        $this->created = [];
        $this->stopServer();
        foreach ($this->temp_files as $file) {
            @unlink($file);
        }
        $this->temp_files = [];
        parent::tearDown();
    }

    /**
     * Stop the fake server
     */
    protected function stopServer(): void {
        if (is_resource($this->server)) {
            $status = proc_get_status($this->server);
            if ($status["running"]) {
                proc_terminate($this->server);
            }
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->server);
        }
        $this->server = null;
        $this->pipes = [];
        foreach ([$this->script_file, $this->log_file] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }
        $this->script_file = null;
        $this->log_file = null;
    }

    /**
     * Start the fake server
     * @param array $connections one script per connection: a list of steps or an array with the keys
     *                           "steps", "greeting", "auto", "tls", ... (see scripted_imap_server.php)
     * @param float $lifetime
     *
     * @return int port
     */
    protected function startServer(array $connections, float $lifetime = 10): int {
        $normalized = [];
        foreach ($connections as $connection) {
            if ($connection === [] || isset($connection[0])) {
                $connection = ["steps" => $connection];
            }
            $connection["steps"] = $connection["steps"] ?? [];
            $connection["auto"] = array_merge($connection["auto"] ?? [], [self::logoutStep()]);
            $normalized[] = $connection;
        }
        $this->connections = $normalized;

        $this->script_file = tempnam(sys_get_temp_dir(), "php-imap-script-");
        $this->log_file = tempnam(sys_get_temp_dir(), "php-imap-scripted-");
        file_put_contents($this->script_file, json_encode(["lifetime" => $lifetime, "connections" => $normalized], JSON_UNESCAPED_SLASHES));

        $command = [PHP_BINARY, __DIR__ . "/scripted_imap_server.php", $this->script_file, $this->log_file];
        $this->server = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $this->pipes);
        self::assertIsResource($this->server, "fake IMAP server could not be started");
        $port = (int)trim((string)fgets($this->pipes[1]));
        if ($port <= 0) {
            self::fail("fake IMAP server did not report its port: " . stream_get_contents($this->pipes[2]));
        }
        return $port;
    }

    /**
     * Start a server with a single connection and return a connected protocol
     * @param array $steps
     * @param array $connection additional connection options (greeting, auto, ...)
     *
     * @return ImapProtocol
     */
    protected function connectedProtocol(array $steps, array $connection = []): ImapProtocol {
        $port = $this->startServer([array_merge($connection, ["steps" => $steps])]);
        $protocol = $this->protocol();
        $protocol->connect("127.0.0.1", $port);
        return $protocol;
    }

    /**
     * Create a protocol which is logged out at tearDown
     * @param mixed $encryption
     * @param bool $cert_validation
     *
     * @return ImapProtocol
     */
    protected function protocol(mixed $encryption = false, bool $cert_validation = false): ImapProtocol {
        $protocol = new ImapProtocol(Config::make(), $cert_validation, $encryption);
        $protocol->setConnectionTimeout(5);
        $this->created[] = $protocol;
        return $protocol;
    }

    /**
     * Create a client for the fake server which is disconnected at tearDown
     * @param int $port
     * @param array $account additional account config
     * @param array $options additional "options" config
     *
     * @return Client
     */
    protected function client(int $port, array $account = [], array $options = []): Client {
        $manager = new ClientManager([
            "options"  => $options,
            "accounts" => [
                "default" => array_merge([
                    "host"          => "127.0.0.1",
                    "port"          => $port,
                    "encryption"    => false,
                    "validate_cert" => false,
                    "username"      => "user@example.com",
                    "password"      => "secret",
                    "protocol"      => "imap",
                    "timeout"       => 5,
                ], $account),
            ],
        ]);
        $client = $manager->account("default");
        $this->created[] = $client;
        return $client;
    }

    /**
     * A script step
     * @param string $expect received line without tag, or a regex starting with "/"
     * @param array|string $reply reply lines, "{tag}" is replaced with the tag of the received line
     * @param array $options delay, close, reject_literal, starttls
     *
     * @return array
     */
    protected static function step(string $expect, array|string $reply = [], array $options = []): array {
        return array_merge(["expect" => $expect, "reply" => (array)$reply], $options);
    }

    /**
     * Step answering LOGIN with OK (Dovecot)
     * @param string $user
     * @param string $password
     *
     * @return array
     */
    protected static function loginStep(string $user = "user@example.com", string $password = "secret"): array {
        return self::step("LOGIN \"$user\" \"$password\"", [
            "{tag} OK [CAPABILITY IMAP4rev1 SASL-IR LOGIN-REFERRALS ID ENABLE IDLE SORT THREAD=REFERENCES MULTIAPPEND UNSELECT CHILDREN NAMESPACE UIDPLUS LIST-EXTENDED I18NLEVEL=1 CONDSTORE QRESYNC ESEARCH SEARCHRES WITHIN CONTEXT=SEARCH LIST-STATUS BINARY MOVE SNIPPET=FUZZY PREVIEW=FUZZY STATUS=SIZE SAVEDATE LITERAL+ NOTIFY QUOTA] Logged in",
        ]);
    }

    /**
     * Auto step answering NOOP (sent by Client::getConnection() / isConnected())
     *
     * @return array
     */
    protected static function noopStep(): array {
        return self::step("NOOP", ["{tag} OK NOOP completed (0.001 + 0.000 secs)."]);
    }

    /**
     * Auto step answering LOGOUT like Dovecot and closing the connection
     *
     * @return array
     */
    protected static function logoutStep(): array {
        return self::step("LOGOUT", ["* BYE Logging out", "{tag} OK Logout completed (0.001 + 0.000 secs)."], ["close" => true]);
    }

    /**
     * IMAP literal
     * @param string $data
     *
     * @return string
     */
    protected static function literal(string $data): string {
        return "{" . strlen($data) . "}\r\n" . $data;
    }

    /**
     * Log entries of the fake server
     *
     * @return array<int, array>
     */
    protected function serverLog(): array {
        $entries = [];
        if ($this->log_file === null || !is_file($this->log_file)) {
            return $entries;
        }
        foreach (file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
            $entries[] = json_decode($row, true);
        }
        return $entries;
    }

    /**
     * Lines received by the fake server (without the automatic NOOP / LOGOUT answers if $with_auto is false)
     * @param int|null $connection only this connection (1 = first)
     * @param bool $with_auto
     *
     * @return array<int, string>
     */
    protected function receivedLines(?int $connection = null, bool $with_auto = false): array {
        $lines = [];
        foreach ($this->serverLog() as $entry) {
            if (!in_array($entry["event"], ["recv", "unexpected"], true)) {
                continue;
            }
            if ($connection !== null && $entry["conn"] !== $connection) {
                continue;
            }
            if (!$with_auto && !empty($entry["auto"])) {
                continue;
            }
            $lines[] = $entry["line"];
        }
        return $lines;
    }

    /**
     * Assert that every scripted step was received in order and nothing unexpected was sent
     * @param float $wait seconds to wait for steps the client does not wait an answer for
     */
    protected function assertScriptCompleted(float $wait = 1.0): void {
        $until = microtime(true) + $wait;
        do {
            $log = $this->serverLog();
            $problems = [];
            $consumed = [];
            foreach ($log as $entry) {
                if ($entry["event"] === "unexpected") {
                    $problems[] = sprintf("connection %d: unexpected line %s, expected %s",
                        $entry["conn"], json_encode($entry["line"], JSON_UNESCAPED_SLASHES), $entry["expected"]);
                } elseif ($entry["event"] === "error") {
                    $problems[] = sprintf("connection %d: %s", $entry["conn"], $entry["line"]);
                } elseif ($entry["event"] === "recv" && $entry["step"] !== null) {
                    $consumed[$entry["conn"]] = $entry["step"] + 1;
                }
            }
            if ($problems) {
                break;
            }
            foreach ($this->connections as $index => $connection) {
                $done = $consumed[$index + 1] ?? 0;
                if ($done < count($connection["steps"])) {
                    $problems[] = sprintf("connection %d: step %d (%s) was never received",
                        $index + 1, $done, $connection["steps"][$done]["expect"]);
                }
            }
            if (!$problems || microtime(true) > $until) {
                break;
            }
            usleep(10000);
        } while (true);

        self::assertSame([], $problems, "fake server script was not followed");
    }

    /**
     * Get a port nothing listens on
     *
     * @return int
     */
    protected static function unusedPort(): int {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr($name, strrpos($name, ":") + 1);
    }

    /**
     * Create a self-signed certificate for 127.0.0.1 / localhost
     *
     * @return array{cert: string, key: string}
     */
    protected function selfSignedCertificate(): array {
        $key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1"]);
        $csr = openssl_csr_new(["commonName" => "localhost"], $key, ["digest_alg" => "sha256"]);
        $cert = openssl_csr_sign($csr, null, $key, 1, ["digest_alg" => "sha256"]);
        openssl_x509_export($cert, $cert_pem);
        openssl_pkey_export($key, $key_pem);

        $cert_file = tempnam(sys_get_temp_dir(), "php-imap-cert-");
        $key_file = tempnam(sys_get_temp_dir(), "php-imap-key-");
        file_put_contents($cert_file, $cert_pem);
        file_put_contents($key_file, $key_pem);
        $this->temp_files[] = $cert_file;
        $this->temp_files[] = $key_file;

        return ["cert" => $cert_file, "key" => $key_file];
    }
}
