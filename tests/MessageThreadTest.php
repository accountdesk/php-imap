<?php
/*
* File: MessageThreadTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Message::thread(), copy() and move() against a mocked multi folder mailbox.
*/

namespace Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

class MessageThreadTest extends TestCase {

    /** @var Client $client */
    protected Client $client;

    /** @var MockObject ImapProtocol mockup */
    protected MockObject $protocol;

    /** @var array $folders folder => [uid => raw message] */
    protected array $folders = [];

    /** @var string $selected */
    protected string $selected = "INBOX";

    /** @var array $searches recorded search queries */
    protected array $searches = [];

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public function setUp(): void {
        $dir = __DIR__.DIRECTORY_SEPARATOR."messages".DIRECTORY_SEPARATOR;
        $this->folders = [
            "INBOX" => [
                1 => file_get_contents($dir."thread_my_topic.eml"),
                2 => file_get_contents($dir."thread_unrelated.eml"),
            ],
            "INBOX/Sent" => [
                7 => file_get_contents($dir."thread_re_my_topic.eml"),
            ],
            "Archive" => [],
        ];
        $this->searches = [];
        $this->client = new Client(Config::make([
            "accounts" => [
                "default" => [
                    'protocol'   => 'imap',
                    'encryption' => 'ssl',
                    'username'   => 'foo@domain.tld',
                    'password'   => 'bar',
                ]
            ]
        ]));
        $this->createProtocolMockup();
        $this->client->openFolder("INBOX");
    }

    /**
     * Split a raw message into header and body
     * @param string $raw
     *
     * @return array
     */
    protected function split(string $raw): array {
        $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
        $pos = strpos($raw, "\r\n\r\n");
        return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
    }

    /**
     * @param array $uids
     * @param int $index
     *
     * @return array
     */
    protected function pick(array $uids, int $index): array {
        $result = [];
        foreach ($uids as $uid) {
            if (isset($this->folders[$this->selected][$uid])) {
                $result[$uid] = $this->split($this->folders[$this->selected][$uid])[$index];
            }
        }
        return $result;
    }

    /** EXAMINE leaves UIDNEXT out (Courier) */
    protected bool $uidnext_via_status_only = false;

    /** @var array<int, string> folders asked via STATUS */
    protected array $status_requests = [];

    /**
     * Create a protocol mockup serving the fake folders
     *
     * @return void
     */
    protected function createProtocolMockup(): void {
        $this->protocol = $this->createMock(ImapProtocol::class);
        $this->protocol->method('connected')->willReturn(true);
        $this->protocol->method('selectFolder')->willReturnCallback(function($folder) {
            $this->selected = $folder;
            return Response::empty()->setResult(["exists" => count($this->folders[$folder] ?? [])]);
        });
        $this->protocol->method('examineFolder')->willReturnCallback(function($folder) {
            $uids = array_keys($this->folders[$folder]);
            $status = ["exists" => count($uids), "uidnext" => $uids ? max($uids) + 1 : 1];
            if ($this->uidnext_via_status_only) {
                // like Courier: UIDNEXT only via STATUS
                unset($status["uidnext"]);
            }
            return Response::empty()->setResult($status);
        });
        $this->protocol->method('folderStatus')->willReturnCallback(function($folder) {
            $this->status_requests[] = $folder;
            $uids = array_keys($this->folders[$folder]);
            return Response::empty()->setResult(["uidnext" => $uids ? max($uids) + 1 : 1]);
        });
        $this->protocol->method('folders')->willReturnCallback(function() {
            $folders = [];
            foreach (array_keys($this->folders) as $name) {
                $folders[$name] = ["delimiter" => "/", "flags" => []];
            }
            return Response::empty()->setResult($folders);
        });
        $this->protocol->method('search')->willReturnCallback(function(array $params) {
            $query = $params[0];
            $this->searches[] = [$this->selected, $query];
            $result = [];
            if (preg_match('/^HEADER "?(Message-ID|In-Reply-To)"? "?<?([^"<>]+)>?"?$/', $query, $matches)) {
                foreach ($this->folders[$this->selected] as $uid => $raw) {
                    if (preg_match('/^'.preg_quote($matches[1], '/').': <'.preg_quote($matches[2], '/').'>/mi', $raw)) {
                        $result[] = $uid;
                    }
                }
            }
            return Response::empty()->setCanBeEmpty(true)->setResult($result);
        });
        $this->protocol->method('headers')->willReturnCallback(function($uids) {
            return Response::empty()->setCanBeEmpty(true)->setResult($this->pick((array)$uids, 0));
        });
        $this->protocol->method('content')->willReturnCallback(function($uids) {
            return Response::empty()->setCanBeEmpty(true)->setResult($this->pick((array)$uids, 1));
        });
        $this->protocol->method('flags')->willReturnCallback(function($uids) {
            $result = [];
            foreach ((array)$uids as $uid) {
                $result[$uid] = ["\\Seen"];
            }
            return Response::empty()->setCanBeEmpty(true)->setResult($result);
        });
        $this->protocol->method('getMessageNumber')->willReturn(Response::empty()->setResult(1));
        $transfer = function($folder, $from) {
            $this->folders[$folder][max(array_keys($this->folders[$folder]) ?: [0]) + 1] = $this->folders[$this->selected][$from];
            return Response::empty()->setResult(true);
        };
        $this->protocol->method('copyMessage')->willReturnCallback($transfer);
        $this->protocol->method('moveMessage')->willReturnCallback(function($folder, $from) use ($transfer) {
            $response = $transfer($folder, $from);
            unset($this->folders[$this->selected][$from]);
            return $response;
        });
        $this->protocol->method('expunge')->willReturn(Response::empty()->setResult(["OK"]));

        $this->client->connection = $this->protocol;
    }

    /**
     * @param int $uid
     * @param string $folder
     *
     * @return Message
     */
    protected function message(int $uid, string $folder = "INBOX"): Message {
        $this->client->openFolder($folder);
        [$header, $body] = $this->split($this->folders[$folder][$uid]);
        $message = Message::make($uid, null, $this->client, $header, $body, ["\\Seen"], IMAP::FT_PEEK, IMAP::ST_UID);
        $events = [];
        foreach (["new", "moved", "copied", "deleted", "restored"] as $name) {
            $events["message"][$name] = RecordingEvent::class;
        }
        $events["flag"] = ["new" => RecordingEvent::class, "deleted" => RecordingEvent::class];
        $message->setEvents($events);
        RecordingEvent::$dispatched = [];
        return $message;
    }

    public function testGetFolder(): void {
        $folder = $this->message(1)->getFolder();

        self::assertInstanceOf(Folder::class, $folder);
        self::assertSame("INBOX", $folder->path);
    }

    public function testThreadFindsRepliesInSentFolder(): void {
        $thread = $this->message(1)->thread();

        self::assertSame(["123@example.com", "456@example.com"], $thread->map(function(Message $m) {
            return (string)$m->message_id;
        })->values()->all());

        // the original message is searched in INBOX and in the sent folder
        self::assertContains(["INBOX", 'HEADER "In-Reply-To" "<123@example.com>"'], $this->searches);
        self::assertContains(["INBOX/Sent", 'HEADER "In-Reply-To" "<123@example.com>"'], $this->searches);
    }

    public function testThreadFromReply(): void {
        $thread = $this->message(7, "INBOX/Sent")->thread(null, $thread, $this->client->getFolderByPath("INBOX"));

        $ids = $thread->map(function(Message $m) {
            return (string)$m->message_id;
        })->values()->all();
        self::assertSame(["456@example.com", "123@example.com"], $ids);
        self::assertContains(["INBOX", 'HEADER "Message-ID" "<123@example.com>"'], $this->searches);
    }

    public function testThreadOfUnrelatedMessage(): void {
        $thread = $this->message(2)->thread();

        self::assertSame(1, $thread->count());
        self::assertSame("999@example.com", (string)$thread->first()->message_id);
    }

    public function testCopy(): void {
        $message = $this->message(1);
        $copy = $message->copy("Archive");

        self::assertInstanceOf(Message::class, $copy);
        self::assertSame(1, $copy->uid);
        self::assertSame("Nuu", (string)$copy->subject);
        self::assertSame("Archive", $copy->getFolderPath());
        self::assertArrayHasKey(1, $this->folders["INBOX"]);

        self::assertCount(1, RecordingEvent::$dispatched);
        self::assertSame([$message, $copy], RecordingEvent::$dispatched[0][1]);
    }

    public function testCopyAndMoveWithoutUidnextInExamine(): void {
        // Courier leaves UIDNEXT out of EXAMINE - copy()/move() have to ask via STATUS, otherwise the new
        // message cannot be found and they return null although the server copied it
        $this->uidnext_via_status_only = true;

        $copy = $this->message(1)->copy("Archive");
        self::assertInstanceOf(Message::class, $copy);
        self::assertSame("Nuu", (string)$copy->subject);

        $moved = $this->message(2)->move("Archive");
        self::assertInstanceOf(Message::class, $moved);
        self::assertSame("Wut", (string)$moved->subject);
        self::assertSame(["Archive", "Archive"], $this->status_requests);
    }

    public function testMoveWithExpunge(): void {
        $message = $this->message(2);
        $this->protocol->expects($this->once())->method('expunge');

        $moved = $message->move("INBOX/Sent", true);

        self::assertInstanceOf(Message::class, $moved);
        self::assertSame(8, $moved->uid);
        self::assertSame("Wut", (string)$moved->subject);
        self::assertArrayNotHasKey(2, $this->folders["INBOX"]);
        self::assertSame([$message, $moved], RecordingEvent::$dispatched[0][1]);
    }

    public function testMoveWithMessageNumbers(): void {
        $message = $this->message(2);
        $message->setSequence(IMAP::ST_MSGN);
        $message->setMsgn(2);

        $moved = $message->move("Archive");

        // the uid of the new message is resolved to a message number first
        self::assertSame(IMAP::ST_MSGN, $moved->getSequence());
        self::assertSame(1, $moved->msgn);
    }
}
