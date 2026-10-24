<?php
/*
* File: ClientServerTest.php
* Category: Test
*
* Description:
*  Client against the scripted fake IMAP server: connect / login / oauth, connection checks and
*  reconnects, folder listing, folder management, quota, ID and the "debug" / "uid_cache" options.
*  Client::getConnection() and isConnected() send a NOOP, those are answered automatically.
*/

namespace Tests;

use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Folder;

class ClientServerTest extends ScriptedServerTestCase {

    /**
     * Connection script with a LOGIN followed by the given steps; NOOP is answered at any time
     * @param array $steps
     * @param array $connection
     *
     * @return array
     */
    protected static function session(array $steps, array $connection = []): array {
        return array_merge([
            "steps" => array_merge([self::loginStep()], $steps),
            "auto"  => [self::noopStep()],
        ], $connection);
    }

    /**
     * Start the server with one session and return a connected client
     * @param array $steps
     * @param array $account
     * @param array $options
     *
     * @return Client
     */
    protected function connectedClient(array $steps, array $account = [], array $options = []): Client {
        $port = $this->startServer([self::session($steps)]);
        return $this->client($port, $account, $options)->connect();
    }

    /*
     * connect / login
     */

    public function testConnectLogsIn(): void {
        $client = $this->connectedClient([]);

        self::assertTrue($client->isConnected());
        self::assertInstanceOf(\Webklex\PHPIMAP\Connection\Protocols\ImapProtocol::class, $client->getConnection());
        $client->disconnect();
        self::assertFalse($client->isConnected());
        $this->assertScriptCompleted();
        self::assertSame(['TAG1 LOGIN "user@example.com" "secret"'], $this->receivedLines());
        self::assertMatchesRegularExpression("/^TAG\\d+ LOGOUT$/", array_slice($this->receivedLines(null, true), -1)[0]);
    }

    public function testConnectWithWrongPassword(): void {
        $port = $this->startServer([[
            "steps" => [self::step('LOGIN "user@example.com" "wrong"', ["{tag} NO [AUTHENTICATIONFAILED] Authentication failed."])],
        ]]);

        // LOGIN is answered with NO: the ImapServerErrorException is passed through (no AuthFailedException)
        $this->expectException(ImapServerErrorException::class);
        $this->expectExceptionMessage("AUTHENTICATIONFAILED");
        $this->client($port, ["password" => "wrong"])->connect();
    }

    public function testConnectFailsWithoutServer(): void {
        $client = $this->client(self::unusedPort());

        $this->expectException(ConnectionFailedException::class);
        @$client->connect();
    }

    public function testConnectWithOauth(): void {
        $token = base64_encode("user=user@example.com\1auth=Bearer ya29.token\1\1");
        $port = $this->startServer([[
            "steps" => [self::step("AUTHENTICATE XOAUTH2 $token", ["{tag} OK user@example.com authenticated (Success)"])],
            "auto"  => [self::noopStep()],
        ]]);

        $client = $this->client($port, ["authentication" => "oauth", "password" => "ya29.token"])->connect();

        self::assertTrue($client->isConnected());
        $this->assertScriptCompleted();
    }

    public function testConnectWithInvalidOauthToken(): void {
        $port = $this->startServer([[
            "steps" => [self::step("/^TAG1 AUTHENTICATE XOAUTH2 \\S+$/", ["{tag} NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)"])],
        ]]);
        $error_log = ini_set("error_log", "/dev/null");

        try {
            $this->client($port, ["authentication" => "oauth", "password" => "expired"])->connect();
            self::fail("ResponseException expected");
        } catch (ResponseException $e) {
            self::assertStringContainsString("Invalid credentials (Failure)", $e->getMessage());
        } finally {
            ini_set("error_log", $error_log === false ? "" : $error_log);
        }
    }

    public function testUnsupportedProtocol(): void {
        if (extension_loaded("imap")) {
            self::markTestSkipped("ext-imap is installed - the legacy protocol is supported");
        }
        $client = $this->client(self::unusedPort(), ["protocol" => "pop3"]);

        $this->expectException(ConnectionFailedException::class);
        $client->connect();
    }

    /*
     * connection checks / reconnect
     */

    public function testCheckConnectionReconnectsWhenNoopFails(): void {
        $port = $this->startServer([
            ["steps" => [self::loginStep()], "auto" => [self::step("NOOP", ["{tag} NO [UNAVAILABLE] Server going down."])]],
            self::session([]),
        ]);
        $client = $this->client($port)->connect();

        self::assertTrue($client->checkConnection(), "a new connection was made");
        self::assertTrue($client->isConnected());
        self::assertFalse($client->checkConnection());
        $this->assertScriptCompleted();
    }

    /**
     * A NOOP answered with something that is no IMAP status means the connection is unusable - not an
     * exception from isConnected() / checkConnection()
     */
    public function testCheckConnectionReconnectsAfterAGarbageNoopAnswer(): void {
        $port = $this->startServer([
            ["steps" => [
                self::loginStep(),
                self::noopStep(),
                self::step('SELECT "INBOX"', ["* 1 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
                // checkConnection() and the disconnect() of the reconnect
                self::step("NOOP", ["{tag} WHAT? Unknown status"]),
                self::step("NOOP", ["{tag} WHAT? Unknown status"]),
            ]],
            self::session([self::step('SELECT "INBOX"', ["* 1 EXISTS", "{tag} OK [READ-WRITE] Select completed."])]),
        ]);
        $client = $this->client($port)->connect();
        $client->openFolder("INBOX");

        self::assertTrue($client->checkConnection(), "a new connection was made");
        // the folder was selected again on the new connection
        self::assertSame("INBOX", $client->getActiveFolder());
        $this->assertScriptCompleted();
    }

    /**
     * checkConnection() retries a failing reconnect once (e.g. the server was restarting)
     */
    public function testCheckConnectionRetriesAFailedReconnectOnce(): void {
        $port = $this->startServer([
            ["steps" => [self::loginStep()], "auto" => [self::step("NOOP", ["{tag} NO [UNAVAILABLE] Server going down."])]],
            ["greeting" => "* BYE Server is restarting", "close_after_greeting" => true],
            self::session([]),
        ]);
        $client = $this->client($port)->connect();

        self::assertFalse($client->checkConnection(), "the retry is reported like an existing connection");
        self::assertTrue($client->isConnected());
        $this->assertScriptCompleted();
    }

    public function testMaskConfiguration(): void {
        $port = self::unusedPort();
        self::assertSame(\Webklex\PHPIMAP\Support\Masks\MessageMask::class, $this->client($port)->getDefaultMessageMask());

        foreach (["message", "attachment"] as $section) {
            try {
                new Client(\Webklex\PHPIMAP\Config::make(["masks" => [$section => "Not\\Existing\\Mask"]]));
                self::fail("MaskNotFoundException expected for $section");
            } catch (\Webklex\PHPIMAP\Exceptions\MaskNotFoundException $e) {
                self::assertStringContainsString("Not\\Existing\\Mask", $e->getMessage());
            }
        }

        $client = $this->client($port);
        foreach (["setDefaultMessageMask", "setDefaultAttachmentMask"] as $setter) {
            try {
                $client->$setter("Not\\Existing\\Mask");
                self::fail("MaskNotFoundException expected for $setter");
            } catch (\Webklex\PHPIMAP\Exceptions\MaskNotFoundException) {
            }
        }
        self::assertSame($client, $client->setDefaultAttachmentMask(\Webklex\PHPIMAP\Support\Masks\AttachmentMask::class));
    }

    public function testReconnect(): void {
        $port = $this->startServer([self::session([]), self::session([])]);
        $client = $this->client($port)->connect();

        $client->reconnect();

        self::assertTrue($client->isConnected());
        $this->assertScriptCompleted();
        // the first session was logged out
        self::assertContains("LOGOUT", array_map(fn($line) => explode(" ", $line, 2)[1] ?? "", $this->receivedLines(1, true)));
    }

    public function testSetTimeoutReconnects(): void {
        $port = $this->startServer([self::session([]), self::session([])]);
        $client = $this->client($port)->connect();

        $connection = $client->setTimeout(7);

        self::assertSame(7, $connection->getConnectionTimeout());
        self::assertSame(7, $client->getTimeout());
        $this->assertScriptCompleted();
    }

    public function testDebugOption(): void {
        $port = $this->startServer([self::session([])]);
        $client = $this->client($port, [], ["debug" => true]);

        ob_start();
        try {
            $client->connect();
            $client->getConnection()->disableDebug();
        } finally {
            $output = ob_get_clean();
        }

        self::assertStringContainsString("<< * OK [CAPABILITY", $output);
        // the password is redacted from the debug output
        self::assertStringContainsString('>> TAG1 LOGIN "user@example.com" "***"', $output);
        self::assertStringNotContainsString('secret', $output);
    }

    public function testUidCacheOption(): void {
        $port = $this->startServer([self::session([
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
            self::step("UID FETCH 1:* (UID)", ["* 1 FETCH (UID 4)", "{tag} OK Fetch completed."]),
        ])]);
        $client = $this->client($port, [], ["uid_cache" => false])->connect();

        // without the cache every lookup fetches the uids
        self::assertSame(4, $client->getConnection()->getUid(1)->validatedData());
        self::assertSame(4, $client->getConnection()->getUid(1)->validatedData());
        $this->assertScriptCompleted();
    }

    /*
     * folders
     */

    public function testGetFoldersHierarchical(): void {
        $client = $this->connectedClient([
            self::step('LIST "" "%"', [
                '* LIST (\HasNoChildren) "/" INBOX',
                '* LIST (\HasChildren) "/" Archive',
                '* LIST (\HasNoChildren \Sent) "/" "Sent Items"',
                "{tag} OK List completed (0.001 + 0.000 secs).",
            ]),
            // children of Archive ("%%" matches like "%")
            self::step('LIST "" "Archive/%%"', [
                '* LIST (\HasNoChildren) "/" Archive/2024',
                '* LIST (\HasNoChildren \Marked) "/" "Archive/&AMQ-rger"',
                "{tag} OK List completed (0.001 + 0.000 secs).",
            ]),
        ]);

        $folders = $client->getFolders();

        self::assertSame(["INBOX", "Archive", "Sent Items"], $folders->map(fn(Folder $folder) => $folder->name)->values()->all());
        $archive = $folders->get(1);
        self::assertTrue($archive->hasChildren());
        self::assertSame("/", $archive->delimiter);
        $children = $archive->getChildren();
        self::assertSame(["2024", "Ärger"], $children->map(fn(Folder $folder) => $folder->name)->values()->all());
        self::assertSame("Archive/&AMQ-rger", $children->get(1)->path);
        self::assertSame("Archive/Ärger", $children->get(1)->full_name);
        self::assertTrue($children->get(1)->marked);
        $this->assertScriptCompleted();
    }

    public function testGetFolderByNameAndPath(): void {
        $list = [
            '* LIST (\HasNoChildren) "/" INBOX',
            '* LIST (\HasChildren) "/" Archive',
            '* LIST (\HasNoChildren) "/" "Archive/&AMQ-rger"',
            "{tag} OK List completed.",
        ];
        $client = $this->connectedClient([
            self::step('LIST "" "*"', $list),
            self::step('LIST "" "*"', $list),
            self::step('LIST "" "*"', $list),
            self::step('LIST "" "*"', $list),
        ]);

        self::assertSame("INBOX", $client->getFolder("INBOX")->path);
        self::assertSame("Archive/&AMQ-rger", $client->getFolder("Archive/Ärger")->path);
        self::assertSame("Archive/&AMQ-rger", $client->getFolderByPath("Archive/&AMQ-rger", true)->path);
        self::assertNull($client->getFolderByName("Nope"));
        $this->assertScriptCompleted();
    }

    public function testGetFoldersWithoutResult(): void {
        $client = $this->connectedClient([
            self::step('LIST "" "*"', ["{tag} OK List completed."]),
            self::step('LIST "" "*"', ["{tag} OK List completed."]),
        ]);

        self::assertCount(0, $client->getFolders(false, null, true));
        $this->expectException(FolderFetchingException::class);
        $client->getFolders(false);
    }

    public function testGetFoldersWithStatus(): void {
        $client = $this->connectedClient([
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" INBOX', '* LIST (\HasNoChildren) "/" Archive', "{tag} OK List completed."]),
            self::step('EXAMINE "INBOX"', ["* 3 EXISTS", "* 0 RECENT", "* OK [UIDVALIDITY 1] UIDs valid", "* OK [UIDNEXT 4] Predicted next UID", "{tag} OK [READ-ONLY] Examine completed."]),
            self::step('EXAMINE "Archive"', ["* 0 EXISTS", "* 0 RECENT", "* OK [UIDVALIDITY 2] UIDs valid", "* OK [UIDNEXT 1] Predicted next UID", "{tag} OK [READ-ONLY] Examine completed."]),
        ]);

        $folders = $client->getFoldersWithStatus(false);

        self::assertSame(["exists" => 3, "recent" => 0, "uidvalidity" => 1, "uidnext" => 4], $folders->get(0)->status);
        self::assertSame(2, $folders->get(1)->status["uidvalidity"]);
        $this->assertScriptCompleted();
    }

    public function testGetFoldersWithStatusWithoutResult(): void {
        $client = $this->connectedClient([
            self::step('LIST "" "*"', ["{tag} OK List completed."]),
            self::step('LIST "" "*"', ["{tag} OK List completed."]),
        ]);

        self::assertCount(0, $client->getFoldersWithStatus(false, null, true));
        $this->expectException(FolderFetchingException::class);
        $client->getFoldersWithStatus(false);
    }

    public function testGetFoldersWithStatusHierarchical(): void {
        $client = $this->connectedClient([
            self::step('LIST "" "%"', ['* LIST (\HasChildren) "/" Archive', "{tag} OK List completed."]),
            self::step('LIST "" "Archive/%%"', ['* LIST (\HasNoChildren) "/" Archive/2024', "{tag} OK List completed."]),
            self::step('EXAMINE "Archive/2024"', ["* 1 EXISTS", "{tag} OK [READ-ONLY] Examine completed."]),
            self::step('EXAMINE "Archive"', ["* 0 EXISTS", "{tag} OK [READ-ONLY] Examine completed."]),
        ]);

        $folders = $client->getFoldersWithStatus();

        self::assertSame(1, $folders->get(0)->getChildren()->get(0)->status["exists"]);
        $this->assertScriptCompleted();
    }

    public function testOpenFolderSelectsOnlyOnce(): void {
        $client = $this->connectedClient([
            self::step('SELECT "INBOX"', ["* 2 EXISTS", "* 0 RECENT", "* OK [UIDVALIDITY 1] UIDs valid", "* OK [UIDNEXT 3] Predicted next UID", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('SELECT "INBOX"', ["* 2 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('EXAMINE "Archive"', ["* 5 EXISTS", "{tag} OK [READ-ONLY] Examine completed."]),
        ]);

        self::assertSame(["exists" => 2, "recent" => 0, "uidvalidity" => 1, "uidnext" => 3], $client->openFolder("INBOX"));
        self::assertSame("INBOX", $client->getActiveFolder());
        self::assertSame([], $client->openFolder("INBOX"));
        self::assertSame(["exists" => 2], $client->openFolder("INBOX", true));
        self::assertSame(["exists" => 5], $client->checkFolder("Archive"));
        self::assertSame("INBOX", $client->getFolderPath());
        $client->setActiveFolder(null);
        self::assertNull($client->getActiveFolder());
        $this->assertScriptCompleted();
    }

    public function testOpenMissingFolder(): void {
        $client = $this->connectedClient([
            self::step('SELECT "Nope"', ["{tag} NO Mailbox doesn't exist: Nope"]),
        ]);

        $this->expectException(ResponseException::class);
        $client->openFolder("Nope");
    }

    public function testCreateFolder(): void {
        $client = $this->connectedClient([
            self::step('SELECT "INBOX"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('CREATE "&AMQ-rger"', ["{tag} OK Create completed."]),
            self::step("EXPUNGE", ["{tag} OK Expunge completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" INBOX', '* LIST (\HasNoChildren) "/" "&AMQ-rger"', "{tag} OK List completed."]),
        ]);
        $client->openFolder("INBOX");

        $folder = $client->createFolder("Ärger");

        self::assertSame("&AMQ-rger", $folder->path);
        self::assertSame("Ärger", $folder->name);
        $this->assertScriptCompleted();
    }

    public function testCreateFolderWithoutExpunge(): void {
        $client = $this->connectedClient([
            self::step('CREATE "Archive/2025"', ["{tag} OK Create completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" Archive/2025', "{tag} OK List completed."]),
        ]);

        self::assertSame("2025", $client->createFolder("Archive/2025", false)->name);
        $this->assertScriptCompleted();
    }

    /**
     * Right after connecting no mailbox is selected - EXPUNGE is answered with BAD then (RFC 3501 6.4.3)
     */
    public function testCreateFolderWithoutSelectedMailbox(): void {
        $client = $this->connectedClient([
            // no EXPUNGE: it would be answered with "BAD No mailbox selected."
            self::step('CREATE "Archive"', ["{tag} OK Create completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" Archive', "{tag} OK List completed."]),
        ]);

        self::assertSame("Archive", $client->createFolder("Archive")->name);
        $this->assertScriptCompleted();
    }

    public function testDeleteFolder(): void {
        $client = $this->connectedClient([
            self::step('SELECT "&AMQ-rger"', ["* 0 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" INBOX', '* LIST (\HasNoChildren) "/" "&AMQ-rger"', "{tag} OK List completed."]),
            // the deleted mailbox was the selected one: no EXPUNGE (it would be answered with BAD)
            self::step('DELETE "&AMQ-rger"', ["{tag} OK Delete completed."]),
        ]);
        $client->openFolder("&AMQ-rger");

        self::assertStringStartsWith("OK Delete completed", $client->deleteFolder("Ärger")[0]);
        self::assertNull($client->getActiveFolder(), "the deleted folder is no longer active");
        $this->assertScriptCompleted();
    }

    public function testDeleteFolderWithoutExpunge(): void {
        $client = $this->connectedClient([
            self::step('LIST "" "*"', ['* LIST (\HasNoChildren) "/" Archive', "{tag} OK List completed."]),
            self::step('DELETE "Archive"', ["{tag} OK Delete completed."]),
        ]);

        $status = $client->deleteFolder("Archive", false);

        self::assertStringStartsWith("OK Delete completed", $status[0]);
        $this->assertScriptCompleted();
    }

    /*
     * ID / quota / expunge
     */

    public function testIdQuotaAndExpunge(): void {
        $client = $this->connectedClient([
            self::step('ID ("name" "accountdesk")', ['* ID ("name" "Dovecot")', "{tag} OK ID completed."]),
            self::step('GETQUOTA "#user/user@example.com"', ['* QUOTA "#user/user@example.com" (STORAGE 10 512)', "{tag} OK Getquota completed."]),
            self::step('GETQUOTAROOT "INBOX"', ['* QUOTAROOT INBOX "User quota"', '* QUOTA "User quota" (STORAGE 10 512)', "{tag} OK Getquotaroot completed."]),
            self::step('SELECT "INBOX"', ["* 2 EXISTS", "{tag} OK [READ-WRITE] Select completed."]),
            self::step("EXPUNGE", ["* 1 EXPUNGE", "{tag} OK Expunge completed."]),
        ]);

        self::assertStringStartsWith('ID ("name" "Dovecot")', $client->Id(["name", "accountdesk"])[0]);
        self::assertSame(["QUOTA", "#user/user@example.com", ["STORAGE", "10", "512"]], $client->getQuota()[0]);
        self::assertSame(["QUOTA", "User quota", ["STORAGE", "10", "512"]], $client->getQuotaRoot()[1]);
        $client->openFolder("INBOX");
        self::assertSame([["1", "EXPUNGE"], ["OK", "Expunge", "completed."]], $client->expunge());
        $this->assertScriptCompleted();
    }
}
