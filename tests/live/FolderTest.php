<?php
/*
* File: FolderTest.php
* Category: -
* Author: M.Goldenbaum
* Created: 04.03.23 03:52
* Updated: -
*
* Description:
*  -
*/

namespace Tests\live;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\EventNotFoundException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageFlagException;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FolderCollection;

/**
 * Class FolderTest
 *
 * @package Tests
 */
class FolderTest extends LiveMailboxTestCase {

    /**
     * Try to create a new query instance
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testQuery(): void {
        $folder = $this->getFolder('INBOX');
        self::assertInstanceOf(Folder::class, $folder);

        self::assertInstanceOf(WhereQuery::class, $folder->query());
        self::assertInstanceOf(WhereQuery::class, $folder->search());
        self::assertInstanceOf(WhereQuery::class, $folder->messages());
    }

    /**
     * Test Folder::hasChildren()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     * @throws EventNotFoundException
     */
    public function testHasChildren(): void {
        $this->requiresCapability("children", "Folder::hasChildren() reads the \\HasChildren attribute of LIST");
        $parent = $this->createParentWithChild();

        $folder = $this->getFolder($parent->path);
        self::assertTrue($folder->hasChildren());

        $delimiter = $this->getManager()->getConfig()->get("options.delimiter");
        $child = $this->getFolder($parent->path . $delimiter . "child");
        self::assertFalse($child->hasChildren());
    }

    /**
     * Create a new test folder with one child folder ("child")
     *
     * @return Folder the parent
     */
    protected function createParentWithChild(): Folder {
        $parent = $this->createTestFolder("php-imap-parent");
        $delimiter = $this->getManager()->getConfig()->get("options.delimiter");
        $child = $parent->getClient()->createFolder($parent->path . $delimiter . "child", false);
        self::assertInstanceOf(Folder::class, $child);

        return $parent;
    }

    /**
     * Test Folder::setChildren()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testSetChildren(): void {
        $this->requiresCapability("children", "Folder::hasChildren() reads the \\HasChildren attribute of LIST");
        $parent = $this->createParentWithChild();

        $folder = $this->getFolder($parent->path);
        self::assertTrue($folder->hasChildren());

        $folder->setChildren(new FolderCollection());
        self::assertTrue($folder->getChildren()->isEmpty());
    }

    /**
     * Test Folder::getChildren()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testGetChildren(): void {
        $this->requiresCapability("children", "Client::getFolders() nests folders by the \\HasChildren attribute of LIST");
        $parent = $this->createParentWithChild();

        $inbox = $parent->getClient()->getFolders()->where('name', 'INBOX')->first();
        self::assertInstanceOf(Folder::class, $inbox);
        self::assertTrue($inbox->hasChildren());
        self::assertFalse($inbox->getChildren()->isEmpty());

        $folder = $inbox->getChildren()->where('path', $parent->path)->first();
        self::assertInstanceOf(Folder::class, $folder);
        self::assertTrue($folder->hasChildren());
        self::assertCount(1, $folder->getChildren());
        self::assertSame("child", $folder->getChildren()->first()->name);
    }

    /**
     * Test Folder::move()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testMove(): void {
        $client = $this->getClient();

        $delimiter = $this->getManager()->getConfig()->get("options.delimiter");
        $folder = $this->createTestFolder("php-imap-move");
        $new_name = "php-imap-moved-" . bin2hex(random_bytes(4));
        $new_folder_path = implode($delimiter, ['INBOX', $new_name]);

        $status = $folder->move($new_folder_path, false);
        self::assertIsArray($status);
        self::assertTrue(str_starts_with($status[0], 'OK'));

        $new_folder = $client->getFolder($new_folder_path);
        self::assertInstanceOf(Folder::class, $new_folder);
        self::assertEquals($new_folder_path, $new_folder->path);
        self::assertEquals($new_name, $new_folder->name);
        self::assertNull($client->getFolderByPath($folder->path, false, true));

        if ($this->deleteFolder($new_folder) === false) {
            $this->fail("Could not delete folder: " . $new_folder->path);
        }
    }

    /**
     * Test Folder::delete()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testDelete(): void {
        $client = $this->getClient();
        $folder = $this->createTestFolder("php-imap-delete");

        if ($this->deleteFolder($folder) === false) {
            $this->fail("Could not delete folder: " . $folder->path);
        }
        self::assertNull($client->getFolderByPath($folder->path, false, true));
    }

    /**
     * Test Folder::overview()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageFlagException
     * @throws MessageHeaderFetchingException
     * @throws MessageNotFoundException
     */
    public function testOverview(): void {
        $folder = $this->createTestFolder("php-imap-overview");

        $folder->select();

        // Test empty overview
        $overview = $folder->overview();
        self::assertIsArray($overview);
        self::assertCount(0, $overview);

        $message = $this->appendMessageTemplate($folder, "plain.eml");

        $overview = $folder->overview();

        self::assertIsArray($overview);
        self::assertCount(1, $overview);

        self::assertEquals($message->from->first()->full, end($overview)["from"]->toString());

        self::assertTrue($message->delete());
    }

    /**
     * Test Folder::appendMessage()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws InvalidMessageDateException
     * @throws MaskNotFoundException
     * @throws MessageContentFetchingException
     * @throws MessageFlagException
     * @throws MessageHeaderFetchingException
     * @throws MessageNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testAppendMessage(): void {
        $folder = $this->getFolder('INBOX');
        self::assertInstanceOf(Folder::class, $folder);

        $message = $this->appendMessageTemplate($folder, "plain.eml");
        self::assertInstanceOf(Message::class, $message);

        self::assertEquals("Example", $message->subject);
        self::assertEquals("to@someone-else.com", $message->to);
        self::assertEquals("from@someone.com", $message->from);

        // Clean up
        $this->assertTrue($message->delete(true));
    }

    /**
     * Test Folder::subscribe()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testSubscribe(): void {
        $folder = $this->createTestFolder("php-imap-subscribe");
        $this->unsubscribeIfSubscribed($folder);

        $status = $folder->subscribe();
        self::assertIsArray($status);
        self::assertTrue(str_starts_with($status[0], 'OK'));
        self::assertContains($folder->path, $this->subscribedPaths($folder));
    }

    /**
     * Some servers subscribe new folders automatically and answer a second SUBSCRIBE with NO (Stalwart)
     * @param Folder $folder
     *
     * @return void
     */
    protected function unsubscribeIfSubscribed(Folder $folder): void {
        if (in_array($folder->path, $this->subscribedPaths($folder), true)) {
            $folder->unsubscribe();
        }
    }

    /**
     * Paths of the subscribed folders below INBOX (LSUB)
     * @param Folder $folder
     *
     * @return string[]
     */
    protected function subscribedPaths(Folder $folder): array {
        $delimiter = $this->getManager()->getConfig()->get("options.delimiter");
        $connection = $folder->getClient()->getConnection();
        $response = $connection->requestAndResponse("LSUB", $connection->escapeString("", "INBOX" . $delimiter . "*"));
        $paths = [];
        foreach ($response->setCanBeEmpty(true)->validatedData() as $item) {
            if (is_array($item) && count($item) === 4 && $item[0] === "LSUB") {
                $paths[] = str_replace(["\\\\", "\\\""], ["\\", "\""], (string)$item[3]);
            }
        }
        return $paths;
    }

    /**
     * Test Folder::unsubscribe()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testUnsubscribe(): void {
        $folder = $this->createTestFolder("php-imap-unsubscribe");
        if (!in_array($folder->path, $this->subscribedPaths($folder), true)) {
            $folder->subscribe();
        }

        $status = $folder->unsubscribe();
        self::assertIsArray($status);
        self::assertTrue(str_starts_with($status[0], 'OK'));
        self::assertNotContains($folder->path, $this->subscribedPaths($folder));
    }

    /**
     * Test Folder::status()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testStatus(): void {
        $folder = $this->createTestFolder("php-imap-status");

        $status = $folder->status();
        self::assertEquals(0, $status['messages']);
        self::assertEquals(0, $status['recent']);
        self::assertEquals(0, $status['unseen']);
        self::assertGreaterThan(0, $status['uidnext']);
        self::assertGreaterThan(0, $status['uidvalidity']);

        $folder->appendMessage("From: from@someone.com\r\nTo: to@someone-else.com\r\nSubject: Status\r\n\r\nHi there!\r\n");
        // STATUS of the selected mailbox is allowed but not reliable (rfc3501 6.3.10)
        $folder->getClient()->openFolder("INBOX", true);
        $status = $folder->status();
        self::assertEquals(1, $status['messages']);
        self::assertEquals(1, $status['unseen']);
    }

    /**
     * Test Folder::examine()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testExamine(): void {
        $folder = $this->getFolder('INBOX');
        self::assertInstanceOf(Folder::class, $folder);

        $status = $folder->examine();
        self::assertTrue(isset($status["flags"]) && count($status["flags"]) > 0);
        if ($this->supports("uidnext_in_select")) {
            self::assertTrue(($status["uidnext"] ?? 0) > 0);
        }
        self::assertTrue(($status["uidvalidity"] ?? 0) > 0);
        self::assertTrue(($status["recent"] ?? -1) >= 0);
        self::assertTrue(($status["exists"] ?? -1) >= 0);
    }

    /**
     * Test Folder::getClient()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testGetClient(): void {
        $folder = $this->getFolder('INBOX');
        self::assertInstanceOf(Folder::class, $folder);
        self::assertInstanceOf(Client::class, $folder->getClient());
    }

    /**
     * Test Folder::setDelimiter()
     *
     * @return void
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function testSetDelimiter(): void {
        $folder = $this->getFolder('INBOX');
        self::assertInstanceOf(Folder::class, $folder);

        $folder->setDelimiter("/");
        self::assertEquals("/", $folder->delimiter);

        $folder->setDelimiter(".");
        self::assertEquals(".", $folder->delimiter);

        $default_delimiter = $this->getManager()->getConfig()->get("options.delimiter", "/");
        $folder->setDelimiter(null);
        self::assertEquals($default_delimiter, $folder->delimiter);
    }

}