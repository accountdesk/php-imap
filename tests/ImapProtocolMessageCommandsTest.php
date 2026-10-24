<?php
/*
* File: ImapProtocolMessageCommandsTest.php
* Category: Test
*
* Description:
*  Message commands of ImapProtocol against the scripted fake IMAP server: FETCH, SEARCH, STORE,
*  COPY / MOVE, APPEND, uid <-> message number conversion and overview(). The server responses follow
*  RFC 3501 examples and real Dovecot / Gmail output.
*/

namespace Tests;

use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;

class ImapProtocolMessageCommandsTest extends ScriptedServerTestCase {

    /**
     * Raw header of a test message
     * @param int $number
     *
     * @return string
     */
    protected static function header(int $number): string {
        return "Return-Path: <sender@example.com>\r\n"
            . "From: Sender <sender@example.com>\r\n"
            . "To: user@example.com\r\n"
            . "Subject: Test message $number\r\n"
            . "Date: Thu, 22 Oct 2026 10:0$number:00 +0000\r\n"
            . "Message-ID: <msg$number@example.com>\r\n"
            . "\r\n";
    }

    /*
     * FETCH
     */

    public function testFetchHeadersByUid(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5,7 (RFC822.HEADER)", [
                "* 1 FETCH (UID 5 RFC822.HEADER " . self::literal(self::header(1)) . ")",
                "* 2 FETCH (UID 7 RFC822.HEADER " . self::literal(self::header(2)) . ")",
                "{tag} OK Fetch completed (0.001 + 0.000 secs).",
            ]),
            self::step("UID FETCH 5:5 (RFC822.HEADER)", [
                "* 1 FETCH (UID 5 RFC822.HEADER " . self::literal(self::header(1)) . ")",
                "{tag} OK Fetch completed (0.001 + 0.000 secs).",
            ]),
        ]);

        self::assertSame([5 => self::header(1), 7 => self::header(2)], $protocol->headers([5, 7])->validatedData());
        self::assertSame([5 => self::header(1)], $protocol->headers(5)->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchContent(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.TEXT)", [
                "* 1 FETCH (UID 5 RFC822.TEXT " . self::literal("Hello\r\nWorld\r\n") . ")",
                "{tag} OK Fetch completed.",
            ]),
            self::step("UID FETCH 5:5 (BODY[TEXT])", [
                "* 1 FETCH (UID 5 BODY[TEXT] " . self::literal("Hello\r\n) (not the end\r\n") . ")",
                "{tag} OK Fetch completed.",
            ]),
            self::step("FETCH 2:2 (RFC822.TEXT)", [
                "* 2 FETCH (RFC822.TEXT " . self::literal("Second") . ")",
                "{tag} OK Fetch completed.",
            ]),
        ]);

        self::assertSame([5 => "Hello\r\nWorld\r\n"], $protocol->content(5)->validatedData());
        self::assertSame([5 => "Hello\r\n) (not the end\r\n"], $protocol->content([5], "BODY")->validatedData());
        self::assertSame([2 => "Second"], $protocol->content([2], "RFC822", IMAP::ST_MSGN)->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchLiteralFollowedByMoreItemsOnTheSameLine(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (BODY[TEXT])", [
                "* 1 FETCH (BODY[TEXT] {5}\r\nHello UID 5)",
                "{tag} OK Fetch completed.",
            ]),
            self::step("UID FETCH 6:6 (BODY[TEXT])", [
                "* 2 FETCH (UID 6 BODY[TEXT] {0}\r\n)",
                "{tag} OK Fetch completed.",
            ]),
        ]);

        self::assertSame([5 => "Hello"], $protocol->content([5], "BODY")->validatedData());
        self::assertSame([6 => ""], $protocol->content([6], "BODY")->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchHeaderAndTextLiteralsInOneResponse(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.HEADER RFC822.TEXT)", [
                "* 1 FETCH (UID 5 RFC822.HEADER " . self::literal(self::header(1)) . " RFC822.TEXT " . self::literal("Body\r\n") . ")",
                "{tag} OK Fetch completed.",
            ]),
        ]);

        self::assertSame([5 => [
            "UID"           => "5",
            "RFC822.HEADER" => self::header(1),
            "RFC822.TEXT"   => "Body\r\n",
        ]], $protocol->fetch(["RFC822.HEADER", "RFC822.TEXT"], [5])->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchSeveralItemsByMessageNumber(): void {
        $protocol = $this->connectedProtocol([
            self::step("FETCH 1:2 (FLAGS RFC822.SIZE INTERNALDATE)", [
                '* 1 FETCH (FLAGS (\Seen) RFC822.SIZE 4286 INTERNALDATE "17-Jul-1996 02:44:25 -0700")',
                '* 2 FETCH (FLAGS () RFC822.SIZE 120 INTERNALDATE "22-Oct-2026 10:00:00 +0000")',
                "{tag} OK Fetch completed.",
            ]),
            self::step("FETCH 3:* (FLAGS)", [
                '* 3 FETCH (FLAGS (\Recent))',
                "{tag} OK Fetch completed.",
            ]),
        ]);

        self::assertSame([
            1 => ["FLAGS" => ['\Seen'], "RFC822.SIZE" => "4286", "INTERNALDATE" => "17-Jul-1996 02:44:25 -0700"],
            2 => ["FLAGS" => [], "RFC822.SIZE" => "120", "INTERNALDATE" => "22-Oct-2026 10:00:00 +0000"],
        ], $protocol->fetch(["FLAGS", "RFC822.SIZE", "INTERNALDATE"], 1, 2, IMAP::ST_MSGN)->validatedData());
        self::assertSame([3 => ['\Recent']], $protocol->fetch("FLAGS", 3, INF, IMAP::ST_MSGN)->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchEnvelopeAndBodyStructureRfcExample(): void {
        $protocol = $this->connectedProtocol([
            self::step("FETCH 12:12 (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)", [
                '* 12 FETCH (FLAGS (\Seen) INTERNALDATE "17-Jul-1996 02:44:25 -0700" RFC822.SIZE 4286 ENVELOPE ("Wed, 17 Jul 1996 02:23:25 -0700 (PDT)" "IMAP4rev1 WG mtg summary and minutes" (("Terry Gray" NIL "gray" "cac.washington.edu")) (("Terry Gray" NIL "gray" "cac.washington.edu")) (("Terry Gray" NIL "gray" "cac.washington.edu")) ((NIL NIL "imap" "cac.washington.edu")) ((NIL NIL "minutes" "CNRI.Reston.VA.US")("John Klensin" NIL "KLENSIN" "MIT.EDU")) NIL NIL "<B27397-0100000@cac.washington.edu>") BODY ("TEXT" "PLAIN" ("CHARSET" "US-ASCII") NIL NIL "7BIT" 3028 92))',
                "{tag} OK FETCH completed",
            ]),
        ]);

        $data = $protocol->fetch(["FLAGS", "INTERNALDATE", "RFC822.SIZE", "ENVELOPE", "BODY"], [12], null, IMAP::ST_MSGN)->validatedData();

        $terry = [["Terry Gray", "NIL", "gray", "cac.washington.edu"]];
        self::assertSame([12 => [
            "FLAGS"        => ['\Seen'],
            "INTERNALDATE" => "17-Jul-1996 02:44:25 -0700",
            "RFC822.SIZE"  => "4286",
            "ENVELOPE"     => [
                "Wed, 17 Jul 1996 02:23:25 -0700 (PDT)",
                "IMAP4rev1 WG mtg summary and minutes",
                $terry, $terry, $terry,
                [["NIL", "NIL", "imap", "cac.washington.edu"]],
                [["NIL", "NIL", "minutes", "CNRI.Reston.VA.US"], ["John Klensin", "NIL", "KLENSIN", "MIT.EDU"]],
                "NIL",
                "NIL",
                "<B27397-0100000@cac.washington.edu>",
            ],
            "BODY"         => ["TEXT", "PLAIN", ["CHARSET", "US-ASCII"], "NIL", "NIL", "7BIT", "3028", "92"],
        ]], $data);
        $this->assertScriptCompleted();
    }

    public function testFetchFindsTheUidAtTheEnd(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.SIZE)", [
                // a flag update of the same message without the requested item
                '* 1 FETCH (UID 5 FLAGS (\Seen))',
                "* 1 FETCH (RFC822.SIZE 2310 UID 5)",
                "{tag} OK Success",
            ]),
            self::step("UID FETCH 6,7 (FLAGS)", [
                '* 2 FETCH (X-GM-MSGID 1278455344230334865 UID 6 FLAGS (\Seen))',
                '* 3 FETCH (UID 7 X-GM-THRID 1266894439832287888 FLAGS ())',
                "{tag} OK Success",
            ]),
        ]);

        self::assertSame([5 => "2310"], $protocol->sizes([5])->validatedData());
        self::assertSame([6 => ['\Seen'], 7 => []], $protocol->flags([6, 7])->validatedData());
        $this->assertScriptCompleted();
    }

    /**
     * Untagged responses between the FETCH responses (RFC 3501 7: EXPUNGE is allowed during UID FETCH)
     */
    public function testFetchIgnoresUnsolicitedResponsesInBetween(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5,6,7 (FLAGS)", [
                '* 1 FETCH (UID 5 FLAGS (\Seen))',
                "* 4 EXISTS",
                '* 9 FETCH (FLAGS (\Seen \Flagged))',
                "* 3 RECENT",
                '* 2 FETCH (UID 6 FLAGS ())',
                "* 8 EXPUNGE",
                "* OK [HIGHESTMODSEQ 100] Highest",
                '* 3 FETCH (UID 7 FLAGS (\Deleted))',
                "{tag} OK Fetch completed.",
            ]),
        ]);

        self::assertSame([5 => ['\Seen'], 6 => [], 7 => ['\Deleted']], $protocol->flags([5, 6, 7])->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchEmptyResult(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 99:99 (FLAGS)", ["{tag} OK Fetch completed (0.001 + 0.000 secs)."]),
        ]);

        $response = $protocol->flags([99]);

        self::assertSame([], $response->data());
        self::assertSame([], $response->setCanBeEmpty(true)->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFetchSingleIdNotFound(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 99:99 (FLAGS)", ["{tag} OK Fetch completed (0.001 + 0.000 secs)."]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("the single id was not found in response");
        $protocol->fetch("FLAGS", 99);
    }

    public function testFetchSingleIdIgnoresOtherMessages(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (FLAGS)", ['* 2 FETCH (UID 6 FLAGS (\Seen))', "{tag} OK Fetch completed."]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("the single id was not found in response");
        $protocol->fetch("FLAGS", 5);
    }

    public function testFetchSingleId(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (FLAGS)", ['* 3 FETCH (UID 5 FLAGS (\Seen))', "{tag} OK Fetch completed."]),
            self::step("FETCH 3:3 (FLAGS)", ['* 3 FETCH (FLAGS (\Seen))', "{tag} OK Fetch completed."]),
        ]);
        $protocol->setConnectionTimeout(1);

        self::assertSame(['\Seen'], $protocol->fetch("FLAGS", 5)->validatedData());
        self::assertSame(['\Seen'], $protocol->fetch("FLAGS", 3, null, IMAP::ST_MSGN)->validatedData());
        $this->assertScriptCompleted();
    }

    /**
     * fetch() does not look at the tagged status, the Response detects the NO line itself (Gmail)
     */
    public function testFetchReportsAFailedCommand(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5,6 (FLAGS)", ['* 1 FETCH (UID 5 FLAGS (\Seen))', "{tag} NO Some messages could not be FETCHed (Failure)"]),
        ]);

        $response = $protocol->flags([5, 6]);
        self::assertSame([5 => ['\Seen']], $response->data());
        self::assertTrue($response->failed());
        $this->assertScriptCompleted();
    }

    public function testFetchFailsWhenTheConnectionIsLostInsideALiteral(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 5:5 (RFC822.TEXT)", [["raw" => "* 1 FETCH (UID 5 RFC822.TEXT {500}\r\nonly a part of the message\r\n"]], ["close" => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("empty response");
        $protocol->content([5]);
    }

    /*
     * SEARCH
     */

    public function testSearch(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID SEARCH ALL", ["* SEARCH 2 84 882", "{tag} OK Search completed (0.001 + 0.000 secs)."]),
            self::step('SEARCH UNSEEN SINCE 1-Feb-1994 FROM "Smith"', ["* 23 EXISTS", "* SEARCH 1 7", "{tag} OK SEARCH completed"]),
        ]);

        self::assertSame(["2", "84", "882"], $protocol->search(["ALL"])->validatedData());
        self::assertSame(["1", "7"], $protocol->search(["UNSEEN", "SINCE", "1-Feb-1994", "FROM", '"Smith"'], IMAP::ST_MSGN)->validatedData());
        $this->assertScriptCompleted();
    }

    public function testSearchEmptyResult(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID SEARCH UNSEEN", ["* SEARCH", "{tag} OK Search completed (0.001 + 0.000 secs)."]),
        ]);

        $response = $protocol->search(["UNSEEN"]);

        self::assertSame([], $response->validatedData());
        self::assertTrue($response->successful());
        $this->assertScriptCompleted();
    }

    public function testSearchLargeResult(): void {
        $ids = range(1, 30000);
        $protocol = $this->connectedProtocol([
            self::step("UID SEARCH ALL", ["* SEARCH " . implode(" ", $ids), "{tag} OK Search completed."]),
        ]);

        $result = $protocol->search(["ALL"])->validatedData();

        self::assertCount(30000, $result);
        self::assertSame("1", $result[0]);
        self::assertSame("30000", $result[29999]);
        $this->assertScriptCompleted();
    }

    public function testSearchFailure(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID SEARCH CHARSET KOI8-R ALL", ["{tag} NO [BADCHARSET (US-ASCII UTF-8)] Unknown charset"]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("BADCHARSET");
        $protocol->search(["CHARSET", "KOI8-R", "ALL"]);
    }

    /*
     * STORE
     */

    public function testStoreSilently(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID STORE 5 +FLAGS.SILENT (\Seen)', ["{tag} OK Store completed (0.001 + 0.000 secs)."]),
            self::step('UID STORE 5:7 -FLAGS.SILENT (\Flagged \Seen)', ["{tag} OK Store completed."]),
            self::step('STORE 2 +X-GM-LABELS.SILENT (Work)', ["{tag} OK Success"]),
        ]);

        self::assertTrue($protocol->store(['\Seen'], 5, null, "+")->successful());
        self::assertTrue($protocol->store(['\Flagged', '\Seen'], 5, 7, "-")->successful());
        self::assertTrue($protocol->store("Work", 2, null, "+", true, IMAP::ST_MSGN, "X-GM-LABELS")->successful());
        $this->assertScriptCompleted();
    }

    public function testStoreReturnsTheNewFlagsRfcExample(): void {

        $protocol = $this->connectedProtocol([
            self::step('STORE 2:4 +FLAGS (\Deleted)', [
                '* 2 FETCH (FLAGS (\Deleted \Seen))',
                '* 3 FETCH (FLAGS (\Keyword \Deleted))',
                '* 4 FETCH (FLAGS (\Deleted \Flagged \Seen))',
                "{tag} OK STORE completed",
            ]),
        ]);

        self::assertSame([
            2 => ['\Deleted', '\Seen'],
            3 => ['\Keyword', '\Deleted'],
            4 => ['\Deleted', '\Flagged', '\Seen'],
        ], $protocol->store(['\Deleted'], 2, 4, "+", false, IMAP::ST_MSGN)->validatedData());
        $this->assertScriptCompleted();
    }

    public function testStoreNotSilentSendsFlagsWithoutSilentSuffix(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID STORE 5:6 +FLAGS (\Seen)', ['* 1 FETCH (UID 5 FLAGS (\Seen))', '* 2 FETCH (UID 6 FLAGS (\Seen \Flagged))', "{tag} OK Store completed."]),
        ]);

        $response = $protocol->store(['\Seen'], 5, 6, "+", false);

        self::assertCount(3, $response->getResponse());
        $this->assertScriptCompleted();
    }

    /**
     * RFC 3501 6.4.8: the FETCH responses of UID STORE contain the UID - Dovecot sends it first
     */
    public function testStoreByUidReturnsTheNewFlags(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID STORE 5 +FLAGS (\Seen)', ['* 1 FETCH (UID 5 FLAGS (\Seen))', "{tag} OK Store completed."]),
        ]);

        $result = $protocol->store(['\Seen'], 5, null, "+", false)->validatedData();
        self::assertContains(['\Seen'], $result);
    }

    public function testStoreWithoutModeReplacesTheFlags(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID STORE 5 FLAGS.SILENT (\Seen)', ["{tag} OK Store completed."]),
        ]);

        $protocol->store(['\Seen'], 5, null, null);
        $this->assertScriptCompleted();
    }

    public function testStoreFailure(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID STORE 5 +FLAGS.SILENT (\Seen)', ["{tag} NO Mailbox is read-only."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $protocol->store(['\Seen'], 5);
    }

    /*
     * COPY / MOVE
     */

    public function testCopy(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID COPY 5 "Archive"', ["{tag} OK [COPYUID 1385478918 5 10] Copy completed (0.002 + 0.000 secs)."]),
            self::step('COPY 5:7 "Archive"', ["{tag} OK Copy completed."]),
            self::step('UID COPY 5,7,9 "Sent Items"', ["{tag} OK [COPYUID 1385478918 5,7,9 11:13] Copy completed."]),
        ]);

        self::assertStringStartsWith("OK [COPYUID 1385478918 5 10]", $protocol->copyMessage("Archive", 5)->validatedData()[0]);
        self::assertTrue($protocol->copyMessage("Archive", 5, 7, IMAP::ST_MSGN)->successful());
        self::assertTrue($protocol->copyManyMessages([5, 7, 9], "Sent Items")->successful());
        $this->assertScriptCompleted();
    }

    public function testCopyToMissingFolder(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID COPY 5 "Nope"', ["{tag} NO [TRYCREATE] Mailbox doesn't exist: Nope"]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("[TRYCREATE]");
        $protocol->copyMessage("Nope", 5);
    }

    public function testMove(): void {
        $protocol = $this->connectedProtocol([
            self::step('UID MOVE 5 "Archive"', [
                "* OK [COPYUID 1385478918 5 10] Moved UIDs.",
                "* 1 EXPUNGE",
                "{tag} OK Move completed (0.001 + 0.000 secs).",
            ]),
            self::step('MOVE 1:2 "Archive"', ["* 1 EXPUNGE", "* 1 EXPUNGE", "{tag} OK Move completed."]),
            self::step('UID MOVE 5,7 "Archive"', ["* OK [COPYUID 1385478918 5,7 11:12] Moved UIDs.", "* 1 EXPUNGE", "* 2 EXPUNGE", "{tag} OK Move completed."]),
        ]);

        $data = $protocol->moveMessage("Archive", 5)->validatedData();
        self::assertCount(3, $data);
        self::assertStringStartsWith("OK Move completed", end($data));
        self::assertTrue($protocol->moveMessage("Archive", 1, 2, IMAP::ST_MSGN)->successful());
        self::assertTrue($protocol->moveManyMessages([5, 7], "Archive")->successful());
        $this->assertScriptCompleted();
    }

    /**
     * Servers without the MOVE extension (RFC 6851) reject the command - moveMessage() is meant to fall back
     * to COPY, STORE \Deleted and EXPUNGE.
     */
    public function testMoveFallsBackToCopyWithoutMoveCapability(): void {
        $this->markTestSkipped("BUG: moveMessage()/moveManyMessages() never reach the COPY/STORE/EXPUNGE fallback - the rejected MOVE throws ImapServerErrorException");

        $protocol = $this->connectedProtocol([
            self::step('UID MOVE 5 "Archive"', ["{tag} BAD Error in IMAP command UID MOVE: Unknown command (0.001 + 0.000 secs)."]),
            self::step('UID COPY 5 "Archive"', ["{tag} OK [COPYUID 1385478918 5 10] Copy completed."]),
            self::step('UID STORE 5 +FLAGS.SILENT (\Deleted)', ["{tag} OK Store completed."]),
            self::step("EXPUNGE", ["* 1 EXPUNGE", "{tag} OK Expunge completed."]),
            self::step('UID MOVE 6,7 "Archive"', ["{tag} BAD Error in IMAP command UID MOVE: Unknown command (0.001 + 0.000 secs)."]),
            self::step('UID COPY 6,7 "Archive"', ["{tag} OK [COPYUID 1385478918 6:7 11:12] Copy completed."]),
            self::step('UID STORE 6:6 +FLAGS.SILENT (\Deleted)', ["{tag} OK Store completed."]),
            self::step('UID STORE 7:7 +FLAGS.SILENT (\Deleted)', ["{tag} OK Store completed."]),
            self::step("EXPUNGE", ["* 1 EXPUNGE", "* 1 EXPUNGE", "{tag} OK Expunge completed."]),
        ], ["greeting" => "* OK [CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS] Server ready."]);

        self::assertTrue($protocol->moveMessage("Archive", 5)->successful());
        self::assertTrue($protocol->moveManyMessages([6, 7], "Archive")->successful());
        $this->assertScriptCompleted();
    }

    /*
     * APPEND
     */

    public function testAppendWithFlagsAndDate(): void {
        $message = self::header(1) . "Hello\r\n";
        $protocol = $this->connectedProtocol([
            self::step('APPEND "INBOX" (\Seen \Flagged) "22-Oct-2026 10:00:00 +0000" {' . strlen($message) . "}\r\n" . $message, [
                "{tag} OK [APPENDUID 1385478918 10] Append completed (0.002 + 0.000 + 0.001 secs).",
            ]),
            self::step('APPEND "Sent Items" {' . strlen($message) . "}\r\n" . $message, ["{tag} OK [APPENDUID 1385478918 11] Append completed."]),
        ]);

        $response = $protocol->appendMessage("INBOX", $message, ['\Seen', '\Flagged'], "22-Oct-2026 10:00:00 +0000");
        self::assertStringStartsWith("OK [APPENDUID 1385478918 10]", $response->validatedData()[0]);
        // the literal was announced, the continuation awaited and then the message sent
        self::assertSame("+ OK\r\n", $response->getResponse()[0]);

        self::assertTrue($protocol->appendMessage("Sent Items", $message)->successful());
        $this->assertScriptCompleted();
    }

    public function testAppendRejectedBeforeTheLiteral(): void {
        $message = self::header(1) . "Hello\r\n";
        $protocol = $this->connectedProtocol([
            self::step('APPEND "INBOX" {' . strlen($message) . "}", ["{tag} NO [OVERQUOTA] Quota exceeded (mailbox for user is full)"], ["reject_literal" => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("failed to send literal string");
        $protocol->appendMessage("INBOX", $message);
    }

    public function testAppendSendsAMessageWithoutLineBreakAsLiteral(): void {
        $this->markTestSkipped("BUG: appendMessage() sends a message without \"\\n\" as quoted string - APPEND requires a literal (RFC 3501 9)");

        $protocol = $this->connectedProtocol([
            self::step("APPEND \"INBOX\" {11}\r\nSubject: hi", ["{tag} OK Append completed."]),
        ]);

        $protocol->appendMessage("INBOX", "Subject: hi");
        $this->assertScriptCompleted();
    }

    /*
     * UID <-> message number
     */

    public function testGetUidUsesTheCache(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "* 2 FETCH (UID 7)", "* 3 FETCH (UID 9)", "{tag} OK Fetch completed (0.001 + 0.000 secs)."]),
            self::step('SELECT "INBOX"', ["* 2 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "* 2 FETCH (UID 9)", "{tag} OK Fetch completed."]),
        ]);

        self::assertSame(7, $protocol->getUid(2)->validatedData());
        self::assertSame([1 => 4, 2 => 7, 3 => 9], $protocol->getUid()->validatedData());
        self::assertSame(3, $protocol->getMessageNumber("9")->validatedData());

        // selecting a folder clears the cache
        $protocol->selectFolder("INBOX");
        self::assertSame(2, $protocol->getMessageNumber("9")->validatedData());
        $this->assertScriptCompleted();
    }

    public function testGetUidWithoutCache(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "* 2 FETCH (UID 5)", "{tag} OK Fetch completed."]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
        ]);
        $protocol->disableUidCache();

        self::assertSame(4, $protocol->getUid(1)->validatedData());
        self::assertSame(5, $protocol->getUid(2)->validatedData());
        try {
            $protocol->getUid(2);
            self::fail("MessageNotFoundException expected");
        } catch (MessageNotFoundException $e) {
            self::assertSame("unique id not found", $e->getMessage());
        }
        $protocol->enableUidCache();
        $this->assertScriptCompleted();
    }

    public function testGetUidOfAnEmptyMailbox(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["{tag} OK Fetch completed (0.001 + 0.000 secs)."]),
            self::step("UID FETCH 1:* (UID)", ["{tag} OK Fetch completed (0.001 + 0.000 secs)."]),
        ]);

        $response = $protocol->getUid();
        self::assertSame([], $response->validatedData());

        $this->expectException(MessageNotFoundException::class);
        $this->expectExceptionMessage("message number not found: 3");
        $protocol->getMessageNumber("3");
    }

    public function testGetUidRefetchesForAnUnknownMessageNumber(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
            // the message was expunged in the meantime
            self::step("UID FETCH 1:* (UID)", ["* 1 EXPUNGE", "{tag} OK Fetch completed."]),
        ]);

        self::assertSame(4, $protocol->getUid(1)->validatedData());
        try {
            $protocol->getUid(2);
            self::fail("MessageNotFoundException expected");
        } catch (MessageNotFoundException $e) {
            self::assertSame("unique id not found", $e->getMessage());
        }
        $this->assertScriptCompleted();
    }

    public function testGetUidWhenTheConnectionIsLost(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)"], ["close" => true]),
        ]);

        // the failed fetch leaves the cache empty
        self::assertSame([], $protocol->getUid()->validatedData());
        $this->assertScriptCompleted();
    }

    public function testGetUidOfAnUnknownMessageNumberStops(): void {
        $this->markTestSkipped("BUG: getUid() with an unknown message number refetches the uids in an endless recursion while the cache is enabled");

        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
        ]);

        try {
            $protocol->getUid(5);
            self::fail("MessageNotFoundException expected");
        } catch (MessageNotFoundException) {
        }
        $this->assertScriptCompleted();
    }

    /*
     * overview()
     */

    public function testOverviewByUid(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "* 2 FETCH (UID 7)", "* 3 FETCH (UID 9)", "{tag} OK Fetch completed."]),
            self::step("UID FETCH 7,9 (RFC822.HEADER)", [
                "* 2 FETCH (UID 7 RFC822.HEADER " . self::literal(self::header(2)) . ")",
                "* 3 FETCH (UID 9 RFC822.HEADER " . self::literal(self::header(3)) . ")",
                "{tag} OK Fetch completed.",
            ]),
        ]);

        $overview = $protocol->overview("5:*", IMAP::ST_UID)->validatedData();

        self::assertSame([7, 9], array_keys($overview));
        self::assertSame("Test message 2", (string)$overview[7]["subject"]);
        self::assertSame("<msg3@example.com>", "<" . $overview[9]["message_id"] . ">");
        $this->assertScriptCompleted();
    }

    public function testOverviewByMessageNumber(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "* 2 FETCH (UID 7)", "* 3 FETCH (UID 9)", "{tag} OK Fetch completed."]),
            self::step("FETCH 1,2 (RFC822.HEADER)", [
                "* 1 FETCH (RFC822.HEADER " . self::literal(self::header(1)) . ")",
                "* 2 FETCH (RFC822.HEADER " . self::literal(self::header(2)) . ")",
                "{tag} OK Fetch completed.",
            ]),
        ]);

        $overview = $protocol->overview("1:2", IMAP::ST_MSGN)->validatedData();

        self::assertSame([1, 2], array_keys($overview));
        self::assertSame("Test message 1", (string)$overview[1]["subject"]);
        $this->assertScriptCompleted();
    }

    public function testOverviewWithoutMatchingMessages(): void {
        $protocol = $this->connectedProtocol([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
        ]);

        self::assertSame([], $protocol->overview("10:20")->validatedData());
        $this->assertScriptCompleted();
    }
}
