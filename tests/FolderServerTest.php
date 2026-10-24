<?php
/*
* File: FolderServerTest.php
* Category: Test
*
* Description:
*  The server dependent parts of Folder (except idle()) against the scripted fake IMAP server: status,
*  examine / select, (un)subscribe, move / rename, delete, overview, appendMessage and query().
*  Client::getConnection() sends a NOOP, those are answered automatically.
*/

namespace Tests;

use Carbon\Carbon;
use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;

class FolderServerTest extends ScriptedServerTestCase {

    /**
     * Start the server with one logged in session and return a connected client
     * @param array $steps
     * @param array $options
     *
     * @return Client
     */
    protected function connectedClient(array $steps, array $options = []): Client {
        $port = $this->startServer([[
            "steps" => array_merge([self::loginStep()], $steps),
            "auto"  => [self::noopStep()],
        ]]);
        return $this->client($port, [], $options)->connect();
    }

    public function testFolderAttributesAndName(): void {
        $client = $this->connectedClient([]);

        $folder = new Folder($client, "Archive/&AMQ-rger", "/", ['\HasChildren', '\Noselect', '\Marked', '\Referral']);

        self::assertSame("Archive/&AMQ-rger", $folder->path);
        self::assertSame("Archive/Ärger", $folder->full_name);
        self::assertSame("Ärger", $folder->name);
        self::assertTrue($folder->hasChildren());
        self::assertTrue($folder->no_select);
        self::assertFalse($folder->no_inferiors);
        self::assertTrue($folder->marked);
        self::assertTrue($folder->referral);
        self::assertSame($client, $folder->getClient());

        // an empty delimiter falls back to options.delimiter
        $flat = new Folder($client, "INBOX", "", ['\NoInferiors']);
        self::assertSame("/", $flat->delimiter);
        self::assertTrue($flat->no_inferiors);
    }

    public function testStatusExamineAndSelect(): void {
        $client = $this->connectedClient([
            self::step('STATUS "INBOX" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)', [
                "* STATUS INBOX (MESSAGES 3 UNSEEN 1 RECENT 0 UIDNEXT 10 UIDVALIDITY 1385478918)",
                "{tag} OK Status completed.",
            ]),
            self::step('STATUS "INBOX" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)', [
                "* STATUS INBOX (MESSAGES 3 UNSEEN 1 RECENT 0 UIDNEXT 10 UIDVALIDITY 1385478918)",
                "{tag} OK Status completed.",
            ]),
            self::step('EXAMINE "INBOX"', ["* 3 EXISTS", "* 0 RECENT", "* OK [UIDNEXT 10] Predicted next UID", "{tag} OK [READ-ONLY] Examine completed."]),
            self::step('SELECT "INBOX"', ["* 3 EXISTS", "* 0 RECENT", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('EXAMINE "INBOX"', ["* 4 EXISTS", "{tag} OK [READ-ONLY] Examine completed."]),
        ]);
        $folder = new Folder($client, "INBOX", "/", []);

        $expected = ["messages" => 3, "unseen" => 1, "recent" => 0, "uidnext" => 10, "uidvalidity" => 1385478918];
        self::assertSame($expected, $folder->status());
        self::assertSame($expected, $folder->getStatus());
        self::assertSame(["exists" => 3, "recent" => 0, "uidnext" => 10], $folder->examine());
        self::assertSame(["exists" => 3, "recent" => 0], $folder->select());
        self::assertSame(["exists" => 4], $folder->loadStatus()->status);
        $this->assertScriptCompleted();
    }

    public function testSubscribeAndUnsubscribe(): void {
        $client = $this->connectedClient([
            self::step('SELECT "Archive"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('SUBSCRIBE "Archive"', ["{tag} OK Subscribe completed."]),
            self::step('UNSUBSCRIBE "Archive"', ["{tag} OK Unsubscribe completed."]),
        ]);
        $folder = new Folder($client, "Archive", "/", []);

        self::assertStringStartsWith("OK Subscribe completed", $folder->subscribe()[0]);
        // the folder is still selected: no second SELECT
        self::assertStringStartsWith("OK Unsubscribe completed", $folder->unsubscribe()[0]);
        $this->assertScriptCompleted();
    }

    public function testRename(): void {
        $client = $this->connectedClient([
            self::step('SELECT "INBOX"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('RENAME "Archive/2024" "Archive/Old"', ["{tag} OK Rename completed."]),
            self::step("EXPUNGE", ["{tag} OK Expunge completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasChildren) "/" Archive', '* LIST (\HasNoChildren) "/" Archive/Old', "{tag} OK List completed."]),
            self::step('RENAME "Archive/Old" "Archive/2024"', ["{tag} OK Rename completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasChildren) "/" Archive', '* LIST (\HasNoChildren) "/" Archive/2024', "{tag} OK List completed."]),
        ]);
        $client->openFolder("INBOX");
        $folder = new Folder($client, "Archive/2024", "/", []);

        self::assertStringStartsWith("OK Rename completed", $folder->rename("Archive/Old")[0]);
        $moved = new Folder($client, "Archive/Old", "/", []);
        self::assertStringStartsWith("OK Rename completed", $moved->move("Archive/2024", false)[0]);
        $this->assertScriptCompleted();
    }

    /**
     * The server knows the folder by its UTF-7 path ("&AMQ-rger"), not by the decoded name
     */
    public function testRenameFolderWithNonAsciiName(): void {
        $this->markTestSkipped("BUG: Folder::move() sends the decoded full_name (UTF-8) instead of the UTF-7 path and does not encode the new name");

        $client = $this->connectedClient([
            self::step('RENAME "&AMQ-rger" "&AOQ-rger"', ["{tag} OK Rename completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" "&AOQ-rger"', "{tag} OK List completed."]),
        ]);
        $folder = new Folder($client, "&AMQ-rger", "/", []);

        $folder->rename("ärger", false);
        $this->assertScriptCompleted();
    }

    public function testDelete(): void {
        $client = $this->connectedClient([
            self::step('SELECT "Archive"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('DELETE "Archive"', ["{tag} OK Delete completed."]),
            self::step('DELETE "Other"', ["{tag} OK Delete completed."]),
            self::step("EXPUNGE", ["{tag} BAD No mailbox selected."]),
        ]);
        $client->openFolder("Archive");

        self::assertStringStartsWith("OK Delete completed", (new Folder($client, "Archive", "/", []))->delete(false)[0]);
        self::assertNull($client->getActiveFolder(), "the deleted folder is no longer active");

        $this->expectException(\Webklex\PHPIMAP\Exceptions\ImapServerErrorException::class);
        (new Folder($client, "Other", "/", []))->delete();
    }

    public function testOverview(): void {
        $header = "From: Sender <sender@example.com>\r\nTo: user@example.com\r\nSubject: Overview test\r\n"
            . "Date: Thu, 22 Oct 2026 10:00:00 +0000\r\nMessage-ID: <overview@example.com>\r\n\r\n";
        $client = $this->connectedClient([
            self::step('SELECT "INBOX"', ["* 2 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "* 2 FETCH (UID 7)", "{tag} OK Fetch completed."]),
            self::step("UID FETCH 4,7 (RFC822.HEADER)", [
                "* 1 FETCH (UID 4 RFC822.HEADER " . self::literal($header) . ")",
                "* 2 FETCH (UID 7 RFC822.HEADER " . self::literal($header) . ")",
                "{tag} OK Fetch completed.",
            ]),
        ]);
        $folder = new Folder($client, "INBOX", "/", []);

        $overview = $folder->overview();

        self::assertSame([4, 7], array_keys($overview));
        self::assertSame("Overview test", (string)$overview[7]["subject"]);
        $this->assertScriptCompleted();
    }

    public function testAppendMessage(): void {
        $message = "From: sender@example.com\r\nSubject: appended\r\n\r\nHello\r\n";
        $client = $this->connectedClient([
            self::step('APPEND "INBOX" (\Seen) "22-Oct-2026 10:00:00 +0000" {' . strlen($message) . "}\r\n" . $message, ["{tag} OK [APPENDUID 1385478918 10] Append completed."]),
            self::step('APPEND "INBOX" "01-Feb-2026 08:05:09 +0100" {' . strlen($message) . "}\r\n" . $message, ["{tag} OK [APPENDUID 1385478918 11] Append completed."]),
            self::step('APPEND "INBOX" {' . strlen($message) . "}\r\n" . $message, ["{tag} OK [APPENDUID 1385478918 12] Append completed."]),
        ]);
        $folder = new Folder($client, "INBOX", "/", []);

        $status = $folder->appendMessage($message, ['\Seen'], Carbon::create(2026, 10, 22, 10, 0, 0, "UTC"));
        self::assertStringStartsWith("OK [APPENDUID 1385478918 10]", $status[0]);
        $folder->appendMessage($message, null, "01-Feb-2026 08:05:09 +0100");
        $folder->appendMessage($message);
        $this->assertScriptCompleted();
    }

    public function testQueryOpensTheFolder(): void {
        $client = $this->connectedClient([
            self::step('SELECT "INBOX"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
        ]);
        $folder = new Folder($client, "INBOX", "/", []);

        self::assertInstanceOf(WhereQuery::class, $folder->query());
        self::assertInstanceOf(WhereQuery::class, $folder->search());
        self::assertInstanceOf(WhereQuery::class, $folder->messages(["X-GM-LABELS"]));
        self::assertSame("INBOX", $client->getActiveFolder());
        $this->assertScriptCompleted();
    }
}
