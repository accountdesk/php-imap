<?php
/*
* File: ImapProtocolFuzzTest.php
* Category: Test
*
* Description:
*  Deterministic fuzz test for the response parsing of ImapProtocol: real server responses (Dovecot,
*  Gmail, RFC 3501 examples) are mutated with fixed seeds (truncated, characters dropped / inserted /
*  replaced, literal sizes changed) and fed to readResponse() and the command parsers. The library may
*  return a (garbage) result or throw one of its exceptions - but never raise a PHP warning / notice /
*  error or hang.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

class ImapProtocolFuzzTest extends TestCase {

    /**
     * Mutations per corpus entry
     */
    const ROUNDS = 300;

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

    /**
     * Real responses, keyed by the command they answer (the tag is always TAG1)
     *
     * @return array<string, string>
     */
    protected static function corpus(): array {
        $header = "From: Sender <sender@example.com>\r\nSubject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?=\r\n\r\n";
        return [
            "fetch" => "* 1 FETCH (UID 5 FLAGS (\\Seen \\Answered) RFC822.SIZE 1234 INTERNALDATE \"22-Oct-2026 10:00:00 +0000\")\r\n"
                . "* 2 FETCH (UID 6 RFC822.HEADER {" . strlen($header) . "}\r\n" . $header . " FLAGS ())\r\n"
                . "* 3 EXISTS\r\n"
                . "TAG1 OK Fetch completed (0.001 + 0.000 secs).\r\n",
            "bodystructure" => "* 1 FETCH (UID 5 BODYSTRUCTURE ((\"TEXT\" \"PLAIN\" (\"CHARSET\" \"US-ASCII\") NIL NIL \"7BIT\" 1152 23)"
                . "(\"TEXT\" \"PLAIN\" (\"CHARSET\" \"US-ASCII\" \"NAME\" \"cc.diff\") \"<960723163407.20117h@cac.washington.edu>\" \"Compiled diff\" \"BASE64\" 4554 73) \"MIXED\"))\r\n"
                . "TAG1 OK Fetch completed.\r\n",
            "header-fields" => "* 1 FETCH (UID 5 BODY[HEADER.FIELDS (SUBJECT FROM)] {16}\r\nSubject: hi\r\n\r\n FLAGS (\\Seen))\r\n"
                . "TAG1 OK Fetch completed.\r\n",
            "envelope" => "* 12 FETCH (UID 7 ENVELOPE (\"Wed, 17 Jul 1996 02:23:25 -0700 (PDT)\" \"IMAP4rev1 WG mtg summary and minutes\" "
                . "((\"Terry Gray\" NIL \"gray\" \"cac.washington.edu\")) NIL NIL ((NIL NIL \"imap\" \"cac.washington.edu\")) NIL NIL NIL "
                . "\"<B27397-0100000@cac.washington.edu>\"))\r\n"
                . "TAG1 OK Fetch completed.\r\n",
            "select" => "* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\r\n"
                . "* OK [PERMANENTFLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft \\*)] Flags permitted.\r\n"
                . "* 231 EXISTS\r\n* 1 RECENT\r\n"
                . "* OK [UNSEEN 12] First unseen.\r\n"
                . "* OK [UIDVALIDITY 1385478918] UIDs valid\r\n"
                . "* OK [UIDNEXT 44292] Predicted next UID\r\n"
                . "TAG1 OK [READ-WRITE] Select completed (0.001 + 0.000 secs).\r\n",
            "list" => "* LIST (\\HasNoChildren) \"/\" INBOX\r\n"
                . "* LIST (\\Noselect \\HasChildren) \"/\" \"&AMQ-rger\"\r\n"
                . "* LIST (\\HasNoChildren) \"\\\\\" \"Quote\\\"d\"\r\n"
                . "* LIST (\\HasNoChildren) \"/\" {10}\r\nLit Folder\r\n"
                . "TAG1 OK List completed (0.001 + 0.000 secs).\r\n",
            "status" => "* 5 EXISTS\r\n* STATUS \"[Gmail]/Sent Mail\" (MESSAGES 231 UNSEEN 3 RECENT 1 UIDNEXT 44292 UIDVALIDITY 1385478918)\r\n"
                . "TAG1 OK Status completed.\r\n",
            "search" => "* SEARCH 2 84 882\r\nTAG1 OK Search completed (0.002 + 0.000 secs).\r\n",
            "store" => "* 1 FETCH (UID 5 FLAGS (\\Seen \\Flagged))\r\n* 2 FETCH (FLAGS (\\Deleted))\r\nTAG1 OK Store completed.\r\n",
            "error" => "* BYE Server shutting down\r\nTAG1 NO [UNAVAILABLE] Temporary failure (0.001 + 0.000 secs).\r\n",
        ];
    }

    /**
     * Mutate the data with a seeded random generator
     * @param string $data
     * @param int $seed
     *
     * @return string
     */
    protected static function mutate(string $data, int $seed): string {
        mt_srand($seed);
        $specials = ["(", ")", "{", "}", "\"", "\\", "[", "]", " ", "\r\n", "*", "NIL", "{5}\r\n", "{999}\r\n", "TAG1 ", "0"];
        $count = mt_rand(1, 3);
        for ($i = 0; $i < $count && $data !== ""; $i++) {
            $pos = mt_rand(0, strlen($data) - 1);
            switch (mt_rand(0, 5)) {
                case 0: // truncate
                    $data = substr($data, 0, $pos);
                    break;
                case 1: // drop a few characters
                    $data = substr($data, 0, $pos) . substr($data, $pos + mt_rand(1, 4));
                    break;
                case 2: // insert a special token
                    $data = substr($data, 0, $pos) . $specials[mt_rand(0, count($specials) - 1)] . substr($data, $pos);
                    break;
                case 3: // replace a character
                    $data[$pos] = chr(mt_rand(32, 126));
                    break;
                case 4: // change a literal size
                    $data = preg_replace_callback('/\{(\d+)\}/', function($m) {
                        return "{" . max(0, (int)$m[1] + mt_rand(-5, 5)) . "}";
                    }, $data, 1);
                    break;
                default: // duplicate a part
                    $data = substr($data, 0, $pos) . substr($data, mt_rand(0, $pos)) ;
                    break;
            }
        }
        return $data;
    }

    /**
     * Protocol reading the given data, writes are discarded
     * @param string $data
     *
     * @return ImapProtocol
     */
    protected function protocolReading(string $data): ImapProtocol {
        $stream = fopen("php://memory", "r+");
        fwrite($stream, $data);
        rewind($stream);
        $protocol = new class(Config::make(), false) extends ImapProtocol {
            public function __destruct() {
            }

            public function write(Response $response, string $data): void {
                $response->addCommand($data . "\r\n");
            }
        };
        $protocol->stream = $stream;
        return $protocol;
    }

    /**
     * Run a parser and check that it either returns or throws an exception of the library
     * @param string $data
     * @param callable $parser
     * @param string $label
     */
    protected function assertParsesCleanly(string $data, callable $parser, string $label): void {
        try {
            $parser($this->protocolReading($data));
        } catch (Throwable $e) {
            if (!str_starts_with(get_class($e), "Webklex\\PHPIMAP\\Exceptions\\")) {
                self::fail(sprintf("%s: %s: %s\nat %s:%d\ninput: %s", $label, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), json_encode($data)));
            }
        }
    }

    /**
     * Parsers fed with each corpus entry
     *
     * @return array<string, callable>
     */
    protected static function parsers(string $command): array {
        $parsers = [
            "readResponse"   => fn(ImapProtocol $p) => $p->readResponse(Response::empty(), "TAG1"),
            "readResponseRaw" => fn(ImapProtocol $p) => $p->readResponse(Response::empty(), "TAG1", true),
        ];
        switch ($command) {
            case "fetch":
            case "bodystructure":
            case "header-fields":
            case "envelope":
                $parsers["fetch"] = fn(ImapProtocol $p) => $p->fetch(["UID", "FLAGS", "RFC822.HEADER", "BODYSTRUCTURE", "ENVELOPE"], [5, 6, 7], null, IMAP::ST_UID);
                $parsers["fetchSingle"] = fn(ImapProtocol $p) => $p->fetch("FLAGS", 5);
                $parsers["fetchMsgn"] = fn(ImapProtocol $p) => $p->fetch(["RFC822.HEADER"], 1, INF, IMAP::ST_MSGN);
                $parsers["getUid"] = fn(ImapProtocol $p) => $p->getUid(3);
                break;
            case "select":
                $parsers["select"] = fn(ImapProtocol $p) => $p->selectFolder("INBOX");
                break;
            case "list":
                $parsers["folders"] = fn(ImapProtocol $p) => $p->folders();
                break;
            case "status":
                $parsers["status"] = fn(ImapProtocol $p) => $p->folderStatus("[Gmail]/Sent Mail");
                break;
            case "search":
                $parsers["search"] = fn(ImapProtocol $p) => $p->search(["ALL"]);
                break;
            case "store":
                $parsers["store"] = fn(ImapProtocol $p) => $p->store(['\Seen'], 5, 6, "+", false);
                break;
            case "error":
                $parsers["noop"] = fn(ImapProtocol $p) => $p->noop();
                $parsers["login"] = fn(ImapProtocol $p) => $p->login("user", "secret");
                $parsers["authenticate"] = fn(ImapProtocol $p) => $p->authenticate("user", "token");
                break;
        }
        return $parsers;
    }

    public function testUnmodifiedCorpusParses(): void {
        foreach (self::corpus() as $command => $data) {
            foreach (self::parsers($command) as $name => $parser) {
                $this->assertParsesCleanly($data, $parser, "$command/$name");
            }
        }
        self::assertTrue(true);
    }

    public function testMutatedResponsesNeverRaisePhpErrors(): void {
        $runs = 0;
        foreach (self::corpus() as $command => $data) {
            for ($seed = 1; $seed <= self::ROUNDS; $seed++) {
                $mutated = self::mutate($data, crc32($command) + $seed);
                foreach (self::parsers($command) as $name => $parser) {
                    $this->assertParsesCleanly($mutated, $parser, "$command/$name seed $seed");
                    $runs++;
                }
            }
        }
        self::assertGreaterThan(1000, $runs);
    }
}
