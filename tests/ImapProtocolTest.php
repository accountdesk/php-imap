<?php
/*
* File: ImapProtocolTest.php
* Category: -
* Author: M.Goldenbaum
* Created: 28.12.22 18:11
* Updated: -
*
* Description:
*  -
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

class ImapProtocolTest extends TestCase {

    /** @var Config $config */
    protected Config $config;

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public function setUp(): void {
        $this->config = Config::make();
    }


    /**
     * ImapProtocol test
     *
     * @return void
     */
    public function testImapProtocol(): void {

        $protocol = new ImapProtocol($this->config, false);
        self::assertSame(false, $protocol->getCertValidation());
        self::assertSame("", $protocol->getEncryption());

        $protocol->setCertValidation(true);
        $protocol->setEncryption("ssl");

        self::assertSame(true, $protocol->getCertValidation());
        self::assertSame("ssl", $protocol->getEncryption());

        $protocol->setSslOptions([
            'verify_peer' => true,
            'cafile' => '/dummy/path/for/testing',
            'peer_fingerprint' => ['md5' => 40],
        ]);

        self::assertSame([
            'verify_peer' => true,
            'cafile' => '/dummy/path/for/testing',
            'peer_fingerprint' => ['md5' => 40],
        ], $protocol->getSslOptions());
    }

    /**
     * Decode a single response line with the protected tokenizer
     * @param string $line
     *
     * @return array
     */
    protected function decodeLine(string $line): array {
        $protocol = new class($this->config, false) extends ImapProtocol {
            public function decode(string $line): array {
                return $this->decodeLine(Response::empty(), $line);
            }
        };
        return $protocol->decode($line);
    }

    /**
     * A quoted string directly followed by a closing parenthesis must stay one token
     *
     * @return void
     */
    public function testDecodeLineQuotedStringBeforeClosingParenthesis(): void {
        self::assertSame(
            ['1', 'FETCH', ['UID', '1', 'INTERNALDATE', '22-Oct-2026 10:00:00 +0000']],
            $this->decodeLine('1 FETCH (UID 1 INTERNALDATE "22-Oct-2026 10:00:00 +0000")')
        );

        // closing more than one list at once
        self::assertSame(
            [['a', ['b', 'c d']], 'e'],
            $this->decodeLine('(a (b "c d")) e')
        );

        // a quoted string followed by a space keeps working as before
        self::assertSame(
            [['UID', '1', 'INTERNALDATE', '22-Oct-2026 10:00:00 +0000', 'RFC822.SIZE', '42']],
            $this->decodeLine('(UID 1 INTERNALDATE "22-Oct-2026 10:00:00 +0000" RFC822.SIZE 42)')
        );

        // an escaped quote followed by a closing parenthesis does not end the string
        self::assertSame(
            [['Test (\\"quoted\\") x']],
            $this->decodeLine('("Test (\\"quoted\\") x")')
        );
    }

    /**
     * Protocol reading the given server data from a memory stream
     * @param string $data raw server data
     *
     * @return ImapProtocol
     */
    protected function protocolReading(string $data): ImapProtocol {
        $stream = fopen("php://memory", "r+");
        fwrite($stream, $data);
        rewind($stream);
        $protocol = new class($this->config, false) extends ImapProtocol {
            public function __destruct() {
                // nothing to log out from
            }

            public function decode(string $line): array {
                return $this->decodeLine(Response::empty(), $line);
            }
        };
        $protocol->stream = $stream;
        return $protocol;
    }

    /**
     * Read one response line from the given server data
     * @param string $data
     *
     * @return array
     */
    protected function readTokens(string $data): array {
        $tokens = [];
        $this->protocolReading($data)->readLine(Response::empty(), $tokens);
        return $tokens;
    }

    public function testDecodeLineAtomsListsAndNil(): void {
        self::assertSame(["1", "FETCH", ["FLAGS", ["\\Seen", "\\Answered"], "UID", "5"]], $this->decodeLine('1 FETCH (FLAGS (\Seen \Answered) UID 5)'));
        self::assertSame(["FLAGS", []], $this->decodeLine("FLAGS ()"));
        self::assertSame([["NIL", "NIL", "imap", "cac.washington.edu"]], $this->decodeLine('(NIL NIL "imap" "cac.washington.edu")'));
        self::assertSame([["a", ["b", ["c"]]], "d"], $this->decodeLine("(a (b (c))) d"));
        self::assertSame([[[]]], $this->decodeLine("(())"));
        self::assertSame(["SEARCH"], $this->decodeLine("SEARCH\r\n"));
        self::assertSame([], $this->decodeLine("\r\n"));
    }

    public function testDecodeLineQuotedStrings(): void {
        self::assertSame(["a b", "c"], $this->decodeLine('"a b" c'));
        self::assertSame(["", "x"], $this->decodeLine('"" x'));
        self::assertSame([["subject {5} (x)", "NIL"]], $this->decodeLine('("subject {5} (x)" NIL)'));
        // escapes are kept as received
        self::assertSame([["C:\\\\", "x"]], $this->decodeLine('("C:\\\\" x)'));
        self::assertSame(['say \\"hi\\"'], $this->decodeLine('"say \\"hi\\""'));
        // lists directly following each other after a quoted string
        self::assertSame([[["NIL", "a"], ["NIL", "b"]]], $this->decodeLine('((NIL "a")(NIL "b"))'));
    }

    /**
     * BODYSTRUCTURE of a multipart message (RFC 3501 7.4.2): the body parts follow each other without a space
     * and a part ends with an atom ("23)(")
     */
    public function testDecodeLineMultipartBodyStructure(): void {
        self::assertSame(
            ["BODYSTRUCTURE", [
                ["TEXT", "PLAIN", ["CHARSET", "US-ASCII"], "NIL", "NIL", "7BIT", "1152", "23"],
                ["TEXT", "PLAIN", ["CHARSET", "US-ASCII", "NAME", "cc.diff"], "<960723163407.20117h@cac.washington.edu>", "Compiled diff", "BASE64", "4554", "73"],
                "MIXED",
            ]],
            $this->decodeLine('BODYSTRUCTURE (("TEXT" "PLAIN" ("CHARSET" "US-ASCII") NIL NIL "7BIT" 1152 23)("TEXT" "PLAIN" ("CHARSET" "US-ASCII" "NAME" "cc.diff") "<960723163407.20117h@cac.washington.edu>" "Compiled diff" "BASE64" 4554 73) "MIXED")')
        );
    }

    /**
     * Nested multipart (multipart/mixed with a multipart/alternative part) and section specs with parentheses
     */
    public function testDecodeLineNestedMultipartAndSections(): void {
        self::assertSame(
            ["BODYSTRUCTURE", [
                [["TEXT", "PLAIN", ["CHARSET", "UTF-8"], "NIL", "NIL", "7BIT", "5", "1"], ["TEXT", "HTML", ["CHARSET", "UTF-8"], "NIL", "NIL", "7BIT", "12", "1"], "ALTERNATIVE"],
                ["APPLICATION", "PDF", ["NAME", "a.pdf"], "NIL", "NIL", "BASE64", "100"],
                "MIXED",
            ]],
            $this->decodeLine('BODYSTRUCTURE ((("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 5 1)("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1) "ALTERNATIVE")("APPLICATION" "PDF" ("NAME" "a.pdf") NIL NIL "BASE64" 100) "MIXED")')
        );
        self::assertSame(
            ["1", "FETCH", ["UID", "5", "BODY[HEADER.FIELDS.NOT (X-SPAM X-VIRUS)]", "v", "FLAGS", []]],
            $this->decodeLine('1 FETCH (UID 5 BODY[HEADER.FIELDS.NOT (X-SPAM X-VIRUS)] "v" FLAGS ())')
        );
        self::assertSame(["1", "FETCH", ["BODY[1.MIME]", "NIL"]], $this->decodeLine("1 FETCH (BODY[1.MIME] NIL)"));
        // response codes keep their parts
        self::assertSame(["OK", "[UIDNEXT", "44292]", "Predicted", "next", "UID"], $this->decodeLine("OK [UIDNEXT 44292] Predicted next UID"));
        // status text with parentheses
        self::assertSame(["OK", "Fetch", "completed", ["0.001", "+", "0.000", "secs"], "."], $this->decodeLine("OK Fetch completed (0.001 + 0.000 secs)."));
    }

    public function testDecodeLineClosesListsTheServerForgot(): void {
        self::assertSame([["a", ["b"]]], $this->decodeLine("(a (b"));
    }

    public function testReadLineLiteralInsideList(): void {
        $header = "Subject: hi\r\nFrom: a@b.c\r\n\r\n";
        self::assertSame(
            ["1", "FETCH", ["UID", "5", "RFC822.HEADER", $header, "FLAGS", ["\\Seen"]]],
            $this->readTokens("* 1 FETCH (UID 5 RFC822.HEADER {" . strlen($header) . "}\r\n" . $header . " FLAGS (\\Seen))\r\n")
        );
    }

    public function testReadLineLiteralFollowedByDataOnTheSameLine(): void {
        self::assertSame(
            ["1", "FETCH", ["BODY[TEXT]", "Hello", "UID", "5"]],
            $this->readTokens("* 1 FETCH (BODY[TEXT] {5}\r\nHello UID 5)\r\n")
        );
        // a literal of size 0
        self::assertSame(
            ["2", "FETCH", ["BODY[TEXT]", "", "UID", "6"]],
            $this->readTokens("* 2 FETCH (BODY[TEXT] {0}\r\n UID 6)\r\n")
        );
    }

    public function testReadLineLiteralSpanningSeveralLines(): void {
        $body = "line 1\r\n(line 2)\r\n{3}\r\n\"line 4\r\n";
        self::assertSame(
            ["1", "FETCH", ["UID", "5", "RFC822.TEXT", $body]],
            $this->readTokens("* 1 FETCH (UID 5 RFC822.TEXT {" . strlen($body) . "}\r\n" . $body . ")\r\n")
        );
    }

    public function testReadLineTwoLiterals(): void {
        self::assertSame(
            ["1", "FETCH", ["UID", "5", "RFC822.HEADER", "H: v\r\n\r\n", "RFC822.TEXT", "body"]],
            $this->readTokens("* 1 FETCH (UID 5 RFC822.HEADER {8}\r\nH: v\r\n\r\n RFC822.TEXT {4}\r\nbody)\r\n")
        );
    }

    public function testReadLineLiteralMailboxName(): void {
        self::assertSame(
            ["LIST", ["\\HasNoChildren"], "/", "Lit Folder"],
            $this->readTokens("* LIST (\\HasNoChildren) \"/\" {10}\r\nLit Folder\r\n")
        );
    }

    /**
     * Dovecot answers BODY[HEADER.FIELDS (SUBJECT FROM)] with the section spec (including the field list) as
     * name of the literal
     */
    public function testReadLineHeaderFieldsSection(): void {
        $fields = "Subject: hi\r\n\r\n";
        self::assertSame(
            ["1", "FETCH", ["UID", "5", "BODY[HEADER.FIELDS (SUBJECT FROM)]", $fields]],
            $this->readTokens("* 1 FETCH (UID 5 BODY[HEADER.FIELDS (SUBJECT FROM)] {" . strlen($fields) . "}\r\n" . $fields . ")\r\n")
        );
    }

    public function testReadLineReportsTheTag(): void {
        $protocol = $this->protocolReading("* 3 EXISTS\r\nTAG7 OK done\r\n");
        $tokens = [];

        self::assertFalse($protocol->readLine(Response::empty(), $tokens, "TAG7"));
        self::assertSame(["3", "EXISTS"], $tokens);
        self::assertTrue($protocol->readLine(Response::empty(), $tokens, "TAG7", true));
        self::assertSame("OK done\r\n", $tokens);
    }

    public function testReadResponse(): void {
        $protocol = $this->protocolReading("* 3 EXISTS\r\nTAG1 OK done\r\n");
        self::assertSame([["3", "EXISTS"], ["OK", "done"]], $protocol->readResponse(Response::empty(), "TAG1"));

        $protocol = $this->protocolReading("* 3 EXISTS\r\nTAG1 OK done\r\n");
        self::assertSame(["3 EXISTS\r\n", "OK done\r\n"], $protocol->readResponse(Response::empty(), "TAG1", true));
    }

    public function testReadResponseErrors(): void {
        foreach (["NO" => ImapServerErrorException::class, "BAD" => ImapServerErrorException::class, "BYE" => ImapServerErrorException::class, "WHAT" => ImapBadRequestException::class] as $status => $class) {
            foreach ([false, true] as $dont_parse) {
                $protocol = $this->protocolReading("TAG1 $status [ALERT] went (really) wrong\r\n");
                try {
                    $protocol->readResponse(Response::empty(), "TAG1", $dont_parse);
                    self::fail("$class expected for $status");
                } catch (ImapServerErrorException|ImapBadRequestException $e) {
                    self::assertInstanceOf($class, $e, $status);
                    self::assertStringContainsString("[ALERT] went", $e->getMessage());
                }
            }
        }
    }

    public function testReadResponseFailsAtTheEndOfTheStream(): void {
        $protocol = $this->protocolReading("* 3 EXISTS\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("empty response");
        $protocol->readResponse(Response::empty(), "TAG1");
    }

    public function testNextLineRecordsAndEchoesInDebugMode(): void {
        $protocol = $this->protocolReading("* OK hello\r\n");
        $protocol->enableDebug();
        $response = Response::empty();

        $this->expectOutputString("<< * OK hello\r\n");
        self::assertSame("* OK hello\r\n", $protocol->nextLine($response));
        self::assertSame(["* OK hello\r\n"], $response->getResponse());
    }

    public function testWriteFailsOnAClosedConnection(): void {
        $protocol = $this->protocolReading("");
        $protocol->stream = fopen("php://memory", "r");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("failed to write - connection closed?");
        @$protocol->write(Response::empty(), "TAG1 NOOP");
    }

    public function testWriteToAPeerThatClosedTheConnectionThrowsWithoutAWarning(): void {
        // No "@" here: PHPUnit turns warnings into exceptions like Laravel does. connected() must
        // report false instead of failing with "fwrite(): ... Broken pipe".
        [$local, $remote] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($remote);
        $protocol = $this->protocolReading("");
        $protocol->stream = $local;

        self::assertFalse($protocol->connected());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("failed to write - connection closed?");
        $protocol->write(Response::empty(), "TAG2 NOOP");
    }

    public function testEscapeStringAndList(): void {
        $protocol = new ImapProtocol($this->config, false);

        self::assertSame('"simple"', $protocol->escapeString("simple"));
        self::assertSame('"a \\"quoted\\" \\\\ value"', $protocol->escapeString('a "quoted" \\ value'));
        self::assertSame(["{4}", "a\r\nb"], $protocol->escapeString("a\r\nb"));
        self::assertSame(['"user"', ["{3}", "p\nw"]], $protocol->escapeString("user", "p\nw"));

        self::assertSame('(\Seen \Flagged)', $protocol->escapeList(['\Seen', '\Flagged']));
        self::assertSame('(UID (BODY[TEXT] FLAGS) ())', $protocol->escapeList(["UID", ["BODY[TEXT]", "FLAGS"], []]));
    }

    public function testBuildSet(): void {
        $protocol = new ImapProtocol($this->config, false);

        self::assertSame(5, $protocol->buildSet(5));
        self::assertSame("5:7", $protocol->buildSet("5", 7));
        self::assertSame("5:*", $protocol->buildSet(5, INF));
    }

    public function testIdleUntaggedResponsesStartEmpty(): void {
        self::assertSame([], (new ImapProtocol($this->config, false))->pullIdleUntaggedResponses());
    }

    public function testLineHelpers(): void {
        $data = "* 3 EXISTS\r\n* 1 RECENT\r\nTAG1 OK [READ-WRITE] done\r\n";
        $helpers = function (string $data) {
            $stream = fopen("php://memory", "r+");
            fwrite($stream, $data);
            rewind($stream);
            $protocol = new class($this->config, false) extends ImapProtocol {
                public function __destruct() {
                }

                public function call(string $method, ...$arguments) {
                    $tag = null;
                    $arguments[] = &$tag;
                    $result = $this->$method(Response::empty(), ...$arguments);
                    return [$result, $tag];
                }
            };
            $protocol->stream = $stream;
            return $protocol;
        };

        self::assertSame([true, null], $helpers($data)->call("assumedNextLineIgnoreUntagged", "TAG1 OK"));
        self::assertSame([true, null], $helpers($data)->call("assumedNextLineIgnoreUntagged", "* 3"));
        self::assertSame(["OK [READ-WRITE] done\r\n", "TAG1"], $helpers($data)->call("nextTaggedLineIgnoreUntagged"));
        // the status has to be at the start of the tagged line
        self::assertSame([true, "TAG1"], $helpers($data)->call("assumedNextTaggedLineIgnoreUntagged", "OK"));
        self::assertSame([false, "TAG1"], $helpers($data)->call("assumedNextTaggedLineIgnoreUntagged", "READ-WRITE"));
        self::assertSame([true, "*"], $helpers($data)->call("assumedNextTaggedLine", "3 EXISTS"));
        self::assertSame([false, "*"], $helpers($data)->call("assumedNextTaggedLine", "EXISTS"));
    }
}
