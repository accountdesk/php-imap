<?php
/*
* File: MessageActionsTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for message actions (flags, delete, restore, size, ...) against a mocked protocol.
*/

namespace Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageFlagException;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageSizeFetchingException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

class MessageActionsTest extends TestCase {

    /** @var Client $client */
    protected Client $client;

    /** @var MockObject ImapProtocol mockup */
    protected MockObject $protocol;

    /** @var array $stores recorded store calls */
    protected array $stores = [];

    /** @var array $flags current flags of the fake message */
    protected array $flags = [];

    /** @var string $header */
    protected string $header = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Action\r\n".
        "Message-ID: <action@example.com>\r\nDate: Mon, 01 Jan 2024 10:00:00 +0000\r\nContent-Type: text/plain";

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public function setUp(): void {
        RecordingEvent::$dispatched = [];
        $this->stores = [];
        $this->flags = ["\\Seen"];
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

        $this->protocol = $this->createMock(ImapProtocol::class);
        $this->protocol->method('connected')->willReturn(true);
        $this->protocol->method('selectFolder')->willReturn(Response::empty()->setResult(["exists" => 1]));
        $this->protocol->method('expunge')->willReturn(Response::empty()->setResult(["OK"]));
        $this->protocol->method('flags')->willReturnCallback(function($uids) {
            return Response::empty()->setCanBeEmpty(true)->setResult([22 => $this->flags]);
        });
        $this->protocol->method('store')->willReturnCallback(function(array $flags, $from, $to, $mode) {
            $this->stores[] = [$flags, $from, $to, $mode];
            foreach ($flags as $flag) {
                foreach (explode(" ", $flag) as $single) {
                    if ($mode === "+") {
                        $this->flags[] = $single;
                    } else {
                        $this->flags = array_values(array_diff($this->flags, [$single]));
                    }
                }
            }
            return Response::empty()->setResult(true);
        });
        $this->client->connection = $this->protocol;
        $this->client->openFolder("INBOX");
    }

    /**
     * Create a message with recording events
     * @param array $flags
     * @param int|null $fetch_options
     *
     * @return Message
     */
    protected function message(array $flags = ["\\Seen"], ?int $fetch_options = IMAP::FT_PEEK): Message {
        $message = Message::make(22, null, $this->client, $this->header, "Body", $flags, $fetch_options, IMAP::ST_UID);
        $events = [];
        foreach (["message" => ["new", "moved", "copied", "deleted", "restored"], "flag" => ["new", "deleted"]] as $section => $names) {
            foreach ($names as $name) {
                $events[$section][$name] = RecordingEvent::class;
            }
        }
        $message->setEvents($events);
        RecordingEvent::$dispatched = [];
        return $message;
    }

    public function testMakeWithPeekKeepsMessageUnseen(): void {
        $this->flags = [];
        $message = Message::make(22, null, $this->client, $this->header, "Body", [], IMAP::FT_PEEK, IMAP::ST_UID);

        // with FT_PEEK the body is fetched with BODY.PEEK[TEXT], which does not set \Seen - nothing to undo,
        // no STORE (and no "flag deleted" event) per unseen message
        self::assertCount(0, $this->stores);
        self::assertFalse($message->hasFlag("seen"));

        $this->client->rfc = "BODY";
        Message::make(22, null, $this->client, $this->header, "Body", [], IMAP::FT_PEEK, IMAP::ST_UID);
        self::assertCount(0, $this->stores);
    }

    /**
     * An rfc without PEEK variant fetches "<rfc>.TEXT", which sets \Seen - FT_PEEK removes it again
     */
    public function testMakeWithPeekRemovesSeenForRfcWithoutPeek(): void {
        $this->flags = [];
        $this->client->rfc = "X-CUSTOM";
        Message::make(22, null, $this->client, $this->header, "Body", [], IMAP::FT_PEEK, IMAP::ST_UID);

        self::assertSame([["\\Seen"], 22, 22, "-"], $this->stores[0]);
    }

    public function testMakeWithoutPeekMarksMessageSeen(): void {
        $this->flags = [];
        $message = Message::make(22, null, $this->client, $this->header, "Body", [], IMAP::FT_UID, IMAP::ST_UID);

        self::assertSame([["\\Seen"], 22, 22, "+"], $this->stores[0]);
        self::assertTrue($message->hasFlag("seen"));
    }

    public function testMakeDoesNothingIfAlreadySeen(): void {
        Message::make(22, null, $this->client, $this->header, "Body", ["\\Seen"], IMAP::FT_UID, IMAP::ST_UID);

        self::assertCount(0, $this->stores);
    }

    public function testSetAndUnsetFlag(): void {
        $message = $this->message();

        self::assertTrue($message->setFlag("Flagged"));
        self::assertSame([["\\Flagged"], 22, 22, "+"], $this->stores[0]);
        self::assertTrue($message->hasFlag("flagged"));

        self::assertTrue($message->unsetFlag("Flagged"));
        self::assertSame([["\\Flagged"], 22, 22, "-"], $this->stores[1]);
        self::assertFalse($message->hasFlag("flagged"));

        self::assertSame([
            [RecordingEvent::class, [$message, "\\Flagged"]],
            [RecordingEvent::class, [$message, "\\Flagged"]],
        ], RecordingEvent::$dispatched);
    }

    public function testFlagArraysAndAliases(): void {
        $message = $this->message();

        $message->addFlag(["Flagged", "Answered"]);
        self::assertSame(["\\Flagged \\Answered"], $this->stores[0][0]);
        self::assertTrue($message->hasFlag("answered"));
        self::assertTrue($message->hasFlag("flagged"));

        $message->removeFlag(["Flagged", "Answered"]);
        self::assertSame("-", $this->stores[1][3]);
        self::assertFalse($message->hasFlag("answered"));
    }

    public function testSetFlagWrapsRuntimeException(): void {
        $message = $this->message();
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $protocol->method('store')->willThrowException(new RuntimeException("broken pipe"));
        $this->client->connection = $protocol;

        try {
            $message->setFlag("Flagged");
            $this->fail("Expected MessageFlagException");
        } catch (MessageFlagException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        $this->expectException(MessageFlagException::class);
        $message->unsetFlag("Flagged");
    }

    public function testDeleteAndRestore(): void {
        $message = $this->message();
        $this->protocol->expects($this->exactly(1))->method('expunge');

        self::assertTrue($message->delete(false));
        self::assertTrue($message->hasFlag("deleted"));
        self::assertTrue($message->restore());
        self::assertFalse($message->hasFlag("deleted"));

        $events = array_map(function($event) { return count($event[1]); }, RecordingEvent::$dispatched);
        // flag new, message deleted, flag deleted, message restored
        self::assertSame([2, 1, 2, 1], $events);
    }

    public function testDeleteWithExpunge(): void {
        $message = $this->message();
        $this->protocol->expects($this->once())->method('expunge');

        self::assertTrue($message->delete());
    }

    public function testDeleteWithForceMoveUsesTrashFolder(): void {
        $message = $this->message();
        // options.common_folders.trash
        $this->protocol->expects($this->once())->method('examineFolder')->with("INBOX/Trash")->willReturn(Response::empty()->setResult(["uidnext" => 0]));

        self::assertTrue($message->delete(false, null, true));
    }

    public function testCopyAndMoveWithoutUidNext(): void {
        $message = $this->message();
        $this->protocol->method('examineFolder')->willReturnOnConsecutiveCalls(
            Response::empty()->setResult(["exists" => 3]),
            Response::empty()->setResult(["uidnext" => 0]),
            Response::empty()->setResult(["exists" => 3]),
            Response::empty()->setResult(["uidnext" => -1])
        );
        $this->protocol->expects($this->never())->method('copyMessage');
        $this->protocol->expects($this->never())->method('moveMessage');

        self::assertNull($message->copy("Archive"));
        self::assertNull($message->copy("Archive"));
        self::assertNull($message->move("Archive"));
        self::assertNull($message->move("Archive"));
    }

    public function testSizeIsFetchedLazily(): void {
        $message = $this->message();
        $this->protocol->expects($this->once())->method('sizes')->willReturn(Response::empty()->setResult([22 => 1234]));

        self::assertSame(1234, $message->size);
        // cached afterwards
        self::assertSame(1234, $message->getSize());
    }

    public function testMissingSizeThrows(): void {
        $message = $this->message();
        $this->protocol->method('sizes')->willReturn(Response::empty()->setResult([21 => 1]));

        $this->expectException(MessageSizeFetchingException::class);
        $message->get("size");
    }

    public function testUidAndMessageNumberAreResolved(): void {
        $this->protocol->method('getMessageNumber')->willReturn(Response::empty()->setResult(3));
        $message = $this->message();
        self::assertSame(3, $message->msgn);

        $this->protocol->method('getUid')->willReturn(Response::empty()->setResult(22));
        $message->setMsgn(3);
        self::assertSame(22, $message->uid);
    }

    public function testConstructorFetchesHeaderBodyAndFlags(): void {
        $this->protocol->method('headers')->willReturn(Response::empty()->setResult([22 => $this->header]));
        $this->protocol->method('content')->willReturn(Response::empty()->setResult([22 => "Fetched body"]));

        $message = new Message(22, null, $this->client, IMAP::FT_UID, true, true, IMAP::ST_UID);

        self::assertSame("Action", (string)$message->subject);
        self::assertSame("Fetched body", $message->getTextBody());
        self::assertTrue($message->hasFlag("seen"));
        self::assertSame("INBOX", $message->getFolderPath());
    }

    public function testConstructorWithoutHeaders(): void {
        $this->protocol->method('headers')->willReturn(Response::empty()->setCanBeEmpty(true)->setResult([]));

        $this->expectException(MessageHeaderFetchingException::class);
        new Message(22, null, $this->client, IMAP::FT_UID, false, false, IMAP::ST_UID);
    }

    public function testConstructorWithoutContent(): void {
        $this->protocol->method('headers')->willReturn(Response::empty()->setResult([22 => $this->header]));
        $this->protocol->method('content')->willReturn(Response::empty()->setResult([21 => "wrong message"]));

        $this->expectException(MessageContentFetchingException::class);
        new Message(22, null, $this->client, IMAP::FT_UID, true, false, IMAP::ST_UID);
    }

    public function testConstructorWrapsContentRuntimeException(): void {
        $this->protocol->method('headers')->willReturn(Response::empty()->setResult([22 => $this->header]));
        $this->protocol->method('content')->willThrowException(new RuntimeException("timeout"));

        try {
            new Message(22, null, $this->client, IMAP::FT_UID, true, false, IMAP::ST_UID);
            $this->fail("Expected MessageContentFetchingException");
        } catch (MessageContentFetchingException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function testConstructorWrapsFlagRuntimeException(): void {
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $protocol->method('selectFolder')->willReturn(Response::empty()->setResult(["exists" => 1]));
        $protocol->method('flags')->willThrowException(new RuntimeException("flags failed"));
        $this->client->connection = $protocol;

        try {
            new Message(22, null, $this->client, IMAP::FT_PEEK, false, true, IMAP::ST_UID);
            $this->fail("Expected MessageFlagException");
        } catch (MessageFlagException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function testAttachmentsUseTheClientMask(): void {
        $this->client->setDefaultAttachmentMask(CustomAttachmentMask::class);
        $body = "--b\r\nContent-Type: text/plain\r\n\r\ntext\r\n".
            "--b\r\nContent-Type: application/pdf; name=\"a.pdf\"\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\n\r\n%PDF\r\n".
            "--b--\r\n";
        $header = str_replace("Content-Type: text/plain", "Content-Type: multipart/mixed; boundary=\"b\"", $this->header);
        $message = Message::make(22, null, $this->client, $header, $body, ["\\Seen"], IMAP::FT_PEEK, IMAP::ST_UID);

        $attachment = $message->getAttachments()->first();
        self::assertSame(CustomAttachmentMask::class, $attachment->getMask());
        self::assertInstanceOf(CustomAttachmentMask::class, $attachment->mask());
    }

    public function testQueryGetMessageHelpers(): void {
        $this->protocol->method('headers')->willReturn(Response::empty()->setResult([22 => $this->header]));
        $this->protocol->method('content')->willReturn(Response::empty()->setResult([22 => "Body"]));

        $query = new \Webklex\PHPIMAP\Query\WhereQuery($this->client);
        $message = $query->getMessageByUid(22);
        self::assertSame(IMAP::ST_UID, $message->getSequence());
        self::assertSame(22, $message->uid);

        $message = $query->getMessageByMsgn(22, 4);
        self::assertSame(IMAP::ST_MSGN, $message->getSequence());
        self::assertSame(22, $message->msgn);
        self::assertSame(4, $message->msglist);
    }
}

/**
 * Attachment mask used to verify the client default mask
 */
class CustomAttachmentMask extends \Webklex\PHPIMAP\Support\Masks\AttachmentMask {
}
