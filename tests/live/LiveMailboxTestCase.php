<?php
/*
* File: LiveMailboxTestCase.php
* Category: -
* Author: M.Goldenbaum
* Created: 04.03.23 03:43
* Updated: -
*
* Description:
*  -
*/

namespace Tests\live;

use PHPUnit\Framework\TestCase;
use Tests\support\ImapTargets;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
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
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Class LiveMailboxTestCase
 *
 * @package Tests
 */
abstract class LiveMailboxTestCase extends TestCase {

    /**
     * Special chars
     */
    const SPECIAL_CHARS = 'A_\\|!"£$%&()=?àèìòùÀÈÌÒÙ<>-@#[]_ß_б_π_€_✔_你_يد_Z_';

    /**
     * Client manager
     * @var ClientManager $manager
     */
    protected static ClientManager $manager;

    /**
     * Folders created with createTestFolder(), deleted in tearDown()
     * @var Folder[] $test_folders
     */
    private array $test_folders = [];

    /**
     * Delete the folders created by the test (best effort, failures are not the test's result)
     *
     * @return void
     */
    protected function tearDown(): void {
        foreach (array_reverse($this->test_folders) as $folder) {
            try {
                // Dovecot drops the connection (BYE) when the selected mailbox is deleted
                $folder->getClient()->checkConnection();
                $folder->getClient()->openFolder("INBOX", true);
                $folder->delete(false);
            } catch (\Throwable) {
            }
        }
        $this->test_folders = [];
        parent::tearDown();
    }

    /**
     * Name of the target in tests/servers/targets.php the live mailbox belongs to (LIVE_MAILBOX_TARGET, set by
     * .claude/scripts/imap-servers.sh live), null for a mailbox configured only by the LIVE_MAILBOX_* variables
     *
     * @return string|null
     */
    final protected function getTarget(): ?string {
        return getenv("LIVE_MAILBOX_TARGET") ?: null;
    }

    /**
     * Check whether the server under test has a capability (see Tests\support\ImapTargets::CAPABILITIES).
     * Without a target the defaults apply, quota support comes from LIVE_MAILBOX_QUOTA_SUPPORT.
     * @param string $capability
     *
     * @return bool
     */
    final protected function supports(string $capability): bool {
        $target = $this->getTarget();
        if ($target !== null) {
            return ImapTargets::supports($target, $capability);
        }
        if ($capability === "quota") {
            return (bool)getenv("LIVE_MAILBOX_QUOTA_SUPPORT");
        }
        if (!array_key_exists($capability, ImapTargets::CAPABILITIES)) {
            throw new \InvalidArgumentException("Unknown capability '$capability'");
        }
        return ImapTargets::CAPABILITIES[$capability];
    }

    /**
     * Skip the test if the server under test lacks a capability
     * @param string $capability
     * @param string $reason what the test needs it for
     *
     * @return void
     */
    final protected function requiresCapability(string $capability, string $reason): void {
        if (!$this->supports($capability)) {
            $this->markTestSkipped(sprintf(
                "%s does not support '%s' (tests/servers/targets.php): %s", $this->getTarget() ?? "server", $capability, $reason
            ));
        }
    }

    /**
     * Create a new, uniquely named folder below INBOX that is deleted after the test
     * @param string $prefix
     *
     * @return Folder
     * @throws \Throwable
     */
    final protected function createTestFolder(string $prefix = "php-imap-test"): Folder {
        $client = $this->getClient()->connect();
        $delimiter = $this->getManager()->getConfig()->get("options.delimiter");
        $folder = $client->createFolder(implode($delimiter, ["INBOX", $prefix . "-" . bin2hex(random_bytes(4))]), false);
        self::assertInstanceOf(Folder::class, $folder);
        $this->test_folders[] = $folder;

        return $folder;
    }

    /**
     * Get the client manager
     *
     * @return ClientManager
     */
    final protected function getManager(): ClientManager {
        if (!isset(self::$manager)) {
            self::$manager = new ClientManager([
                'options' => [
                    "debug" => $_ENV["LIVE_MAILBOX_DEBUG"] ?? false,
                    // hierarchy delimiter of the server under test (e.g. "." for Cyrus/Courier/GreenMail)
                    "delimiter" => getenv("LIVE_MAILBOX_DELIMITER") ?: "/",
                ],
                'accounts' => [
                    'default' => [
                        'host'          => getenv("LIVE_MAILBOX_HOST"),
                        'port'          => getenv("LIVE_MAILBOX_PORT"),
                        'encryption'    => getenv("LIVE_MAILBOX_ENCRYPTION"),
                        'validate_cert' => getenv("LIVE_MAILBOX_VALIDATE_CERT"),
                        'username'      => getenv("LIVE_MAILBOX_USERNAME"),
                        'password'      => getenv("LIVE_MAILBOX_PASSWORD"),
                        'authentication' => getenv("LIVE_MAILBOX_AUTHENTICATION") ?: null, // "oauth" for XOAUTH2
                        'protocol'      => 'imap', //might also use imap, [pop3 or nntp (untested)]
                    ],
                ],
            ]);
        }
        return self::$manager;
    }

    /**
     * Get the client
     *
     * @return Client
     * @throws MaskNotFoundException
     */
    final protected function getClient(): Client {
        if (!getenv("LIVE_MAILBOX") ?? false) {
            $this->markTestSkipped("This test requires a live mailbox. Please set the LIVE_MAILBOX environment variable to run this test.");
        }
        return $this->getManager()->account('default');
    }

    /**
     * Get special chars
     *
     * @return string
     */
    final protected function getSpecialChars(): string {
        return self::SPECIAL_CHARS;
    }

    /**
     * Get a folder
     * @param string $folder_path
     *
     * @return Folder
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws MaskNotFoundException
     * @throws ResponseException
     * @throws RuntimeException
     * @throws FolderFetchingException
     */
    final protected function getFolder(string $folder_path = "INDEX"): Folder {
        $client = $this->getClient();
        self::assertInstanceOf(Client::class, $client->connect());

        $folder = $client->getFolderByPath($folder_path);
        self::assertInstanceOf(Folder::class, $folder);

        return $folder;
    }

    /**
     * Append a message to a folder
     * @param Folder $folder
     * @param string $message
     *
     * @return Message
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageFlagException
     * @throws MessageHeaderFetchingException
     * @throws ResponseException
     * @throws RuntimeException
     */
    final protected function appendMessage(Folder $folder, string $message): Message {
        $status = $folder->select();
        if (!isset($status['uidnext']) && !$this->supports("uidnext_in_select")) {
            // the server does not report UIDNEXT on SELECT (Courier), STATUS does
            $status['uidnext'] = $folder->status()['uidnext'] ?? null;
        }
        if (!isset($status['uidnext'])) {
            $this->fail("No UIDNEXT returned");
        }

        $response = $folder->appendMessage($message);
        $valid_response = false;
        foreach ($response as $line) {
            if (str_starts_with($line, 'OK')) {
                $valid_response = true;
                break;
            }
        }
        if (!$valid_response) {
            $this->fail("Failed to append message: ".implode("\n", $response));
        }

        $message = $folder->messages()->getMessageByUid($status['uidnext']);
        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    /**
     * Append a message template to a folder
     * @param Folder $folder
     * @param string $template
     *
     * @return Message
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageFlagException
     * @throws MessageHeaderFetchingException
     * @throws ResponseException
     * @throws RuntimeException
     */
    final protected function appendMessageTemplate(Folder $folder, string $template): Message {
        return $this->appendMessage($folder, $this->loadMessageTemplate($template));
    }

    /**
     * Raw content of a message template (tests/messages)
     * @param string $template
     *
     * @return string
     */
    final protected function loadMessageTemplate(string $template): string {
        return file_get_contents(implode(DIRECTORY_SEPARATOR, [__DIR__, "..", "messages", $template]));
    }

    /**
     * Assert that Message::getSize() is the size of the message as the server stores it (BODY[] as fetched),
     * and that the server stores the rfc5322 form (CRLF line endings) of what was appended. The
     * exact number differs between servers (Courier appends a final CRLF), so it is not hard-coded.
     * @param Message $message
     * @param string $appended raw message as given to appendMessage()
     *
     * @return void
     * @throws \Throwable
     */
    final protected function assertSizeOfStoredMessage(Message $message, string $appended): void {
        $connection = $message->getClient()->getConnection();
        $message->getFolder()->select();
        // the whole message - RFC822.HEADER is not reliable (GreenMail omits the empty line ending the header)
        $raw = $connection->fetch(["BODY.PEEK[]"], (int)$message->uid, null, IMAP::ST_UID)->validatedData();
        $stored = strlen(is_array($raw) ? (string)reset($raw) : (string)$raw);

        self::assertEquals($stored, $message->getSize(), "RFC822.SIZE differs from the size of the fetched message");
        self::assertGreaterThanOrEqual(
            strlen(preg_replace("/\r?\n/", "\r\n", $appended)), (int)$message->getSize(),
            "the stored message is smaller than the appended one with CRLF line endings (bare LF stored verbatim?)"
        );
    }

    /**
     * Delete a folder if it is given
     * @param Folder|null $folder
     *
     * @return bool
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws ResponseException
     * @throws RuntimeException
     */
    final protected function deleteFolder(?Folder $folder = null): bool {
        if ($folder !== null && $folder->getClient()->getFolderPath() === $folder->path) {
            // Courier refuses to delete the selected folder, Dovecot sends BYE
            $folder->getClient()->openFolder("INBOX", true);
        }
        $response = $folder?->delete(false);
        if (is_array($response)) {
            $valid_response = false;
            foreach ($response as $line) {
                if (str_starts_with($line, 'OK')) {
                    $valid_response = true;
                    break;
                }
            }
            if (!$valid_response) {
                $this->fail("Failed to delete mailbox: ".implode("\n", $response));
            }
            return $valid_response;
        }
        return false;
    }
}