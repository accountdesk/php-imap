<?php
/*
* File: ImapProtocolMailboxTest.php
* Category: Test
*
* Description:
*  Mailbox commands of ImapProtocol against the scripted fake IMAP server: SELECT / EXAMINE, STATUS,
*  LIST, CREATE / RENAME / DELETE, (UN)SUBSCRIBE, EXPUNGE and QUOTA. The server responses follow
*  RFC 3501 / RFC 2087 examples and real Dovecot / Gmail output.
*/

namespace Tests;

use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

class ImapProtocolMailboxTest extends ScriptedServerTestCase {

    /*
     * SELECT / EXAMINE
     */

    public function testSelectFolderParsesTheRfcExample(): void {
        $protocol = $this->connectedProtocol([
            self::step('SELECT "INBOX"', [
                "* 172 EXISTS",
                "* 1 RECENT",
                "* OK [UNSEEN 12] Message 12 is first unseen",
                "* OK [UIDVALIDITY 3857529045] UIDs valid",
                "* OK [UIDNEXT 4392] Predicted next UID",
                '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)',
                '* OK [PERMANENTFLAGS (\Deleted \Seen \*)] Limited',
                "* OK [HIGHESTMODSEQ 715194045007] Highest",
                "{tag} OK [READ-WRITE] SELECT completed",
            ]),
        ]);

        $response = $protocol->selectFolder("INBOX");

        self::assertTrue($response->successful());
        // "flags" keeps the list as parsed: one entry holding the flag list
        self::assertEquals([
            "exists"      => 172,
            "recent"      => 1,
            "unseen"      => 12,
            "uidvalidity" => 3857529045,
            "uidnext"     => 4392,
            "flags"       => [['\Answered', '\Flagged', '\Deleted', '\Seen', '\Draft']],
        ], $response->validatedData());
        $this->assertScriptCompleted();
    }

    public function testExamineFolderDovecot(): void {
        $protocol = $this->connectedProtocol([
            self::step('EXAMINE "Archive/2024"', [
                "* OK [CLOSED] Previous mailbox closed.",
                '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft $Forwarded)',
                "* OK [PERMANENTFLAGS ()] Read-only mailbox.",
                "* 0 EXISTS",
                "* 0 RECENT",
                "* OK [UIDVALIDITY 1729000000] UIDs valid",
                "* OK [UIDNEXT 1] Predicted next UID",
                "* OK [HIGHESTMODSEQ 1] Highest",
                "{tag} OK [READ-ONLY] Examine completed (0.001 + 0.000 secs).",
            ]),
        ]);

        $status = $protocol->examineFolder("Archive/2024")->validatedData();

        self::assertSame(0, $status["exists"]);
        self::assertSame(0, $status["recent"]);
        self::assertSame(1729000000, $status["uidvalidity"]);
        self::assertSame(1, $status["uidnext"]);
        self::assertSame([['\Answered', '\Flagged', '\Deleted', '\Seen', '\Draft', '$Forwarded']], $status["flags"]);
        self::assertArrayNotHasKey("unseen", $status);
        $this->assertScriptCompleted();
    }

    public function testSelectFolderEscapesTheName(): void {
        $protocol = $this->connectedProtocol([
            self::step('SELECT "Quote\\"d \\\\ Folder"', ["* 1 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
        ]);

        self::assertSame(["exists" => 1], $protocol->selectFolder('Quote"d \\ Folder')->validatedData());
        $this->assertScriptCompleted();
    }

    public function testSelectMissingFolderFails(): void {
        $protocol = $this->connectedProtocol([
            self::step('SELECT "Nope"', ["{tag} NO Mailbox doesn't exist: Nope (0.001 + 0.000 secs)."]),
        ]);

        $response = $protocol->selectFolder("Nope");

        self::assertTrue($response->failed());
        self::assertSame(["request failed"], $response->getErrors());
        try {
            $response->validatedData();
            self::fail("ResponseException expected");
        } catch (ResponseException $e) {
            self::assertStringContainsString("request failed", $e->getMessage());
        }
        $this->assertScriptCompleted();
    }

    public function testSelectFolderFailsWhenTheConnectionIsLost(): void {
        $protocol = $this->connectedProtocol([
            self::step('SELECT "INBOX"', ["* 3 EXISTS", "* BYE Server shutting down."], ["close" => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("empty response");
        $protocol->selectFolder("INBOX");
    }

    /*
     * STATUS
     */

    public function testFolderStatus(): void {
        $protocol = $this->connectedProtocol([
            self::step('STATUS "INBOX" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)', [
                "* STATUS INBOX (MESSAGES 231 UNSEEN 3 RECENT 1 UIDNEXT 44292 UIDVALIDITY 1385478918)",
                "{tag} OK Status completed (0.001 + 0.000 secs).",
            ]),
        ]);

        self::assertSame([
            "messages"    => 231,
            "unseen"      => 3,
            "recent"      => 1,
            "uidnext"     => 44292,
            "uidvalidity" => 1385478918,
        ], $protocol->folderStatus("INBOX")->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFolderStatusWithQuotedNameAndCustomItems(): void {
        $protocol = $this->connectedProtocol([
            self::step('STATUS "[Gmail]/Sent Mail" (MESSAGES HIGHESTMODSEQ)', [
                '* STATUS "[Gmail]/Sent Mail" (MESSAGES 3 HIGHESTMODSEQ 25)',
                "{tag} OK Success",
            ]),
        ]);

        self::assertSame(["messages" => 3, "highestmodseq" => 25], $protocol->folderStatus("[Gmail]/Sent Mail", ["MESSAGES", "HIGHESTMODSEQ"])->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFolderStatusOfMissingFolder(): void {
        $protocol = $this->connectedProtocol([
            self::step('STATUS "Nope" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)', ["{tag} NO Mailbox doesn't exist: Nope (0.001 + 0.000 secs)."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("Mailbox doesn't exist: Nope");
        $protocol->folderStatus("Nope");
    }

    public function testFolderStatusWithoutStatusResponse(): void {
        $protocol = $this->connectedProtocol([
            self::step('STATUS "INBOX" (MESSAGES)', ["{tag} OK Status completed."]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("folder status could not be fetched");
        $protocol->folderStatus("INBOX", ["MESSAGES"]);
    }

    /**
     * RFC 3501 7: a server may send unilateral untagged responses (e.g. EXISTS for the selected mailbox) with
     * any command response.
     */
    public function testFolderStatusIgnoresUnsolicitedResponses(): void {
        $protocol = $this->connectedProtocol([
            self::step('STATUS "Archive" (MESSAGES)', [
                "* 5 EXISTS",
                "* STATUS Archive (MESSAGES 12)",
                "{tag} OK Status completed.",
            ]),
        ]);

        self::assertSame(["messages" => 12], $protocol->folderStatus("Archive", ["MESSAGES"])->validatedData());
    }

    /*
     * LIST
     */

    public function testFolders(): void {
        $protocol = $this->connectedProtocol([
            self::step('LIST "" "*"', [
                '* LIST (\HasNoChildren) "/" INBOX',
                '* LIST (\HasChildren) "/" Archive',
                '* LIST (\HasNoChildren \UnMarked) "/" Archive/2024',
                '* LIST (\Noselect \HasChildren) "/" "&AMQ-rger"',
                '* LIST (\HasNoChildren \Sent) "/" "Sent Items"',
                '* LIST (\HasNoChildren) "/" "Quote\\"d"',
                '* LIST () "/" Empty',
                '* LIST (\HasNoChildren) "/" ' . self::literal("Lit Folder"),
                "{tag} OK List completed (0.001 + 0.000 secs).",
            ]),
        ]);

        $folders = $protocol->folders()->validatedData();

        self::assertSame([
            "INBOX"        => ["delimiter" => "/", "flags" => ['\HasNoChildren']],
            "Archive"      => ["delimiter" => "/", "flags" => ['\HasChildren']],
            "Archive/2024" => ["delimiter" => "/", "flags" => ['\HasNoChildren', '\UnMarked']],
            "&AMQ-rger"    => ["delimiter" => "/", "flags" => ['\Noselect', '\HasChildren']],
            "Sent Items"   => ["delimiter" => "/", "flags" => ['\HasNoChildren', '\Sent']],
            'Quote"d'      => ["delimiter" => "/", "flags" => ['\HasNoChildren']],
            "Empty"        => ["delimiter" => "/", "flags" => []],
            "Lit Folder"   => ["delimiter" => "/", "flags" => ['\HasNoChildren']],
        ], $folders);
        $this->assertScriptCompleted();
    }

    public function testFoldersGmailWithPattern(): void {
        $protocol = $this->connectedProtocol([
            self::step('LIST "" "[Gmail]/%"', [
                '* LIST (\All \HasNoChildren) "/" "[Gmail]/All Mail"',
                '* LIST (\HasNoChildren \Trash) "/" "[Gmail]/Trash"',
                "{tag} OK Success",
            ]),
        ], ["greeting" => "* OK Gimap ready for requests from 127.0.0.1"]);

        self::assertSame([
            "[Gmail]/All Mail" => ["delimiter" => "/", "flags" => ['\All', '\HasNoChildren']],
            "[Gmail]/Trash"    => ["delimiter" => "/", "flags" => ['\HasNoChildren', '\Trash']],
        ], $protocol->folders("", "[Gmail]/%")->validatedData());
        $this->assertScriptCompleted();
    }

    public function testFoldersEmptyResult(): void {
        $protocol = $this->connectedProtocol([
            self::step('LIST "" "Nope/%"', ["{tag} OK List completed (0.001 + 0.000 secs)."]),
        ]);

        $response = $protocol->folders("", "Nope/%");

        self::assertSame([], $response->validatedData());
        self::assertTrue($response->canBeEmpty());
        $this->assertScriptCompleted();
    }

    public function testFoldersFailure(): void {
        $protocol = $this->connectedProtocol([
            self::step('LIST "" "*"', ["{tag} BAD Error in IMAP command LIST: Invalid arguments."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $protocol->folders();
    }

    public function testFoldersUnescapesTheDelimiter(): void {
        $protocol = $this->connectedProtocol([
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "\\\\" INBOX', "{tag} OK LIST completed"]),
        ]);

        self::assertSame(["INBOX" => ["delimiter" => "\\", "flags" => ['\HasNoChildren']]], $protocol->folders()->validatedData());
    }

    /*
     * CREATE / RENAME / DELETE / SUBSCRIBE / UNSUBSCRIBE
     */

    public function testFolderManagementCommands(): void {
        $protocol = $this->connectedProtocol([
            self::step('CREATE "Archive/2025"', ["{tag} OK Create completed (0.001 + 0.000 secs)."]),
            self::step('RENAME "Archive/2025" "Archive/&AMQ-rger"', ["{tag} OK Rename completed (0.001 + 0.000 secs)."]),
            self::step('SUBSCRIBE "Archive/&AMQ-rger"', ["{tag} OK Subscribe completed (0.001 + 0.000 secs)."]),
            self::step('UNSUBSCRIBE "Archive/&AMQ-rger"', ["{tag} OK Unsubscribe completed (0.001 + 0.000 secs)."]),
            self::step('DELETE "Archive/&AMQ-rger"', ["{tag} OK Delete completed (0.001 + 0.000 secs)."]),
        ]);

        self::assertSame(["TAG1 OK Create completed (0.001 + 0.000 secs).\r\n"], $protocol->createFolder("Archive/2025")->getResponse());
        self::assertStringStartsWith("OK Rename completed", $protocol->renameFolder("Archive/2025", "Archive/&AMQ-rger")->validatedData()[0]);
        self::assertStringStartsWith("OK Subscribe completed", $protocol->subscribeFolder("Archive/&AMQ-rger")->validatedData()[0]);
        self::assertStringStartsWith("OK Unsubscribe completed", $protocol->unsubscribeFolder("Archive/&AMQ-rger")->validatedData()[0]);
        self::assertStringStartsWith("OK Delete completed", $protocol->deleteFolder("Archive/&AMQ-rger")->validatedData()[0]);
        $this->assertScriptCompleted();
    }

    public function testCreateExistingFolder(): void {
        $protocol = $this->connectedProtocol([
            self::step('CREATE "INBOX"', ["{tag} NO [ALREADYEXISTS] Mailbox already exists"]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("[ALREADYEXISTS] Mailbox already exists");
        $protocol->createFolder("INBOX");
    }

    public function testDeleteMissingFolder(): void {
        $protocol = $this->connectedProtocol([
            self::step('DELETE "Nope"', ["{tag} NO [NONEXISTENT] Mailbox doesn't exist: Nope"]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("[NONEXISTENT]");
        $protocol->deleteFolder("Nope");
    }

    public function testRenameToExistingFolder(): void {
        $protocol = $this->connectedProtocol([
            self::step('RENAME "A" "B"', ["{tag} NO [ALREADYEXISTS] Target mailbox already exists"]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $protocol->renameFolder("A", "B");
    }

    /*
     * EXPUNGE
     */

    public function testExpungeParsesTheRfcExampleAndClearsTheUidCache(): void {
        $protocol = $this->connectedProtocol([
            self::step("EXPUNGE", ["* 3 EXPUNGE", "* 3 EXPUNGE", "* 5 EXPUNGE", "* 8 EXPUNGE", "{tag} OK EXPUNGE completed"]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
        ]);
        $protocol->setUidCache([4, 7, 9]);

        self::assertSame([
            ["3", "EXPUNGE"], ["3", "EXPUNGE"], ["5", "EXPUNGE"], ["8", "EXPUNGE"], ["OK", "EXPUNGE", "completed"],
        ], $protocol->expunge()->validatedData());

        // the cache was cleared: the uids are fetched again
        self::assertSame([1 => 4], $protocol->getUid()->validatedData());
        $this->assertScriptCompleted();
    }

    public function testExpungeWithoutSelectedMailbox(): void {
        $protocol = $this->connectedProtocol([
            self::step("EXPUNGE", ["{tag} BAD No mailbox selected."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $protocol->expunge();
    }

    /*
     * QUOTA (RFC 2087)
     */

    public function testGetQuota(): void {
        $protocol = $this->connectedProtocol([
            self::step('GETQUOTA "#user/user@example.com"', [
                '* QUOTA "#user/user@example.com" (STORAGE 1024 10240 MESSAGE 10 1000)',
                "{tag} OK Getquota completed",
            ]),
        ]);

        self::assertSame([
            ["QUOTA", "#user/user@example.com", ["STORAGE", "1024", "10240", "MESSAGE", "10", "1000"]],
            ["OK", "Getquota", "completed"],
        ], $protocol->getQuota("user@example.com")->validatedData());
        $this->assertScriptCompleted();
    }

    public function testGetQuotaRootRfcExample(): void {
        $protocol = $this->connectedProtocol([
            self::step('GETQUOTAROOT "INBOX"', ['* QUOTAROOT INBOX ""', '* QUOTA "" (STORAGE 10 512)', "{tag} OK Getquota completed"]),
        ]);

        self::assertSame([
            ["QUOTAROOT", "INBOX", ""],
            ["QUOTA", "", ["STORAGE", "10", "512"]],
            ["OK", "Getquota", "completed"],
        ], $protocol->getQuotaRoot()->validatedData());
        $this->assertScriptCompleted();
    }

    public function testGetQuotaNotSupported(): void {
        $protocol = $this->connectedProtocol([
            self::step('GETQUOTA "#user/user"', ["{tag} BAD Error in IMAP command GETQUOTA: Unknown command."]),
        ]);

        $this->expectException(ImapServerErrorException::class);
        $protocol->getQuota("user");
    }

    public function testGetQuotaRootQuotesTheMailboxName(): void {

        $protocol = $this->connectedProtocol([
            self::step('GETQUOTAROOT "Sent Items"', ['* QUOTAROOT "Sent Items" ""', '* QUOTA "" (STORAGE 10 512)', "{tag} OK Getquota completed"]),
        ]);

        $protocol->getQuotaRoot("Sent Items");
        $this->assertScriptCompleted();
    }
}
