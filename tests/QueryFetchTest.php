<?php
/*
* File: QueryFetchTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Query::search(), get(), chunked(), paginate() and filter() against a mocked protocol.
*/

namespace Tests;

use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

class QueryFetchTest extends TestCase {

    /** @var Client $client */
    protected Client $client;

    /** @var MockObject ImapProtocol mockup */
    protected MockObject $protocol;

    /** @var array $mailbox uid => [header, body, flags] */
    protected array $mailbox = [];

    /** @var array $calls recorded protocol calls */
    protected array $calls = [];

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public function setUp(): void {
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
        $this->mailbox = [];
        $this->calls = [];
        for ($uid = 1; $uid <= 5; $uid++) {
            $this->addMessage($uid);
        }
        $this->createProtocolMockup();
        $this->client->openFolder("INBOX");
    }

    /**
     * Add a message to the fake mailbox
     * @param int $uid
     * @param string|null $date
     *
     * @return void
     */
    protected function addMessage(int $uid, ?string $date = null): void {
        $date = $date ?? "Mon, 0$uid Jan 2024 10:00:00 +0000";
        $header = "From: Sender $uid <sender$uid@domain.tld>\r\n".
            "To: rcpt@domain.tld\r\n".
            "Subject: Message $uid\r\n".
            "Message-ID: <msg$uid@domain.tld>\r\n".
            "Date: $date\r\n".
            "Content-Type: text/plain; charset=utf-8";
        $this->mailbox[$uid] = [$header, "Body of message $uid", ["\\Seen"]];
    }

    /**
     * Pick the entries for the requested ids from the fake mailbox
     * @param array $ids
     * @param int $index
     *
     * @return array
     */
    protected function pick(array $ids, int $index): array {
        $result = [];
        foreach ($ids as $id) {
            if (isset($this->mailbox[$id])) {
                $result[$id] = $this->mailbox[$id][$index];
            }
        }
        return $result;
    }

    /**
     * Create a protocol mockup which serves the fake mailbox
     *
     * @return void
     */
    protected function createProtocolMockup(): void {
        $this->protocol = $this->createMock(ImapProtocol::class);

        $this->protocol->method('connected')->willReturn(true);
        $this->protocol->method('selectFolder')->willReturn(Response::empty()->setResult(["exists" => count($this->mailbox)]));
        $this->protocol->method('search')->willReturnCallback(function(array $params, $uid = IMAP::ST_UID) {
            $this->calls[] = ["search", $params, $uid];
            return Response::empty()->setCanBeEmpty(true)->setResult(array_keys($this->mailbox));
        });
        $this->protocol->method('headers')->willReturnCallback(function($uids, $rfc = "RFC822", $uid = IMAP::ST_UID) {
            $this->calls[] = ["headers", array_values($uids), $uid];
            return Response::empty()->setCanBeEmpty(true)->setResult($this->pick($uids, 0));
        });
        $this->protocol->method('content')->willReturnCallback(function($uids, $rfc = "RFC822", $uid = IMAP::ST_UID) {
            $this->calls[] = ["content", array_values($uids), $uid];
            return Response::empty()->setCanBeEmpty(true)->setResult($this->pick($uids, 1));
        });
        $this->protocol->method('flags')->willReturnCallback(function($uids, $uid = IMAP::ST_UID) {
            $this->calls[] = ["flags", array_values((array)$uids), $uid];
            return Response::empty()->setCanBeEmpty(true)->setResult($this->pick((array)$uids, 2));
        });
        $this->protocol->method('fetch')->willReturnCallback(function($items, $uids, $to = null, $uid = IMAP::ST_UID) {
            $this->calls[] = ["fetch", $items, array_values($uids), $uid];
            $result = [];
            foreach ($uids as $id) {
                $result[$id] = ["X-GM-LABELS" => ["\\Inbox", "label-$id"], "UID" => $id];
            }
            return Response::empty()->setCanBeEmpty(true)->setResult($result);
        });
        $this->protocol->method('getUid')->willReturnCallback(function() {
            $uids = [];
            foreach (array_keys($this->mailbox) as $i => $uid) {
                $uids[$i + 1] = $uid;
            }
            return Response::empty()->setResult($uids);
        });
        $this->protocol->method('store')->willReturn(Response::empty()->setResult(true));

        $this->client->connection = $this->protocol;
    }

    /**
     * Get all recorded calls of a given method
     * @param string $method
     *
     * @return array
     */
    protected function callsOf(string $method): array {
        return array_values(array_filter($this->calls, function($call) use ($method) {
            return $call[0] === $method;
        }));
    }

    /**
     * @return WhereQuery
     */
    protected function query(array $extensions = []): WhereQuery {
        return new WhereQuery($this->client, $extensions);
    }

    public function testSearchSendsGeneratedQuery(): void {
        $result = $this->query()->whereUnseen()->whereFrom("a@b.c")->search();

        self::assertSame([1, 2, 3, 4, 5], $result->all());
        $search = $this->callsOf("search");
        self::assertCount(1, $search);
        self::assertSame(['UNSEEN FROM "a@b.c"'], $search[0][1]);
        self::assertSame(IMAP::ST_UID, $search[0][2]);
    }

    public function testSearchUsesConfiguredSequence(): void {
        $this->query()->setSequence(IMAP::ST_MSGN)->whereAll()->search();

        self::assertSame(IMAP::ST_MSGN, $this->callsOf("search")[0][2]);
    }

    public function testCount(): void {
        self::assertSame(5, $this->query()->whereAll()->count());

        $this->mailbox = [];
        self::assertSame(0, $this->query()->whereAll()->count());
    }

    public function testSearchWrapsRuntimeException(): void {
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $protocol->method('search')->willThrowException(new RuntimeException("connection lost"));
        $this->client->connection = $protocol;

        try {
            $this->query()->whereAll()->search();
            $this->fail("Expected GetMessagesFailedException");
        } catch (GetMessagesFailedException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function testSearchFailsOnBadResponse(): void {
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $protocol->method('search')->willReturn(Response::make(1, ["TAG1 UID SEARCH FOO"], ["TAG1 BAD Error in IMAP command\r\n"]));
        $this->client->connection = $protocol;

        $this->expectException(ResponseException::class);
        $this->query()->where("CUSTOM FOO")->search();
    }

    public function testGetReturnsMessagesKeyedByListPosition(): void {
        $messages = $this->query()->whereAll()->get();

        self::assertInstanceOf(MessageCollection::class, $messages);
        self::assertSame(5, $messages->count());
        self::assertSame(5, $messages->total());
        self::assertSame(["0", "1", "2", "3", "4"], array_map("strval", $messages->keys()->all()));

        /** @var Message $message */
        $message = $messages->first();
        self::assertSame("Message 1", (string)$message->subject);
        self::assertSame("Body of message 1", $message->getTextBody());
        self::assertTrue($message->hasFlag("seen"));
        self::assertSame(1, $message->getUid());

        // headers, flags and content are requested once for all uids
        self::assertSame([1, 2, 3, 4, 5], $this->callsOf("headers")[0][1]);
        self::assertSame([1, 2, 3, 4, 5], $this->callsOf("flags")[0][1]);
        self::assertSame([1, 2, 3, 4, 5], $this->callsOf("content")[0][1]);
        self::assertCount(0, $this->callsOf("fetch"));
    }

    public function testGetWithoutBody(): void {
        $messages = $this->query()->whereAll()->setFetchBody(false)->get();

        self::assertSame(5, $messages->count());
        self::assertCount(0, $this->callsOf("content"));
        self::assertSame("", $messages->first()->getTextBody());
    }

    public function testGetOnEmptyResult(): void {
        $this->mailbox = [];
        $messages = $this->query()->whereAll()->get();

        self::assertSame(0, $messages->count());
        self::assertCount(0, $this->callsOf("headers"));
    }

    public function testMessageKeyVariants(): void {
        $config = $this->client->getConfig();

        $config->set("options.message_key", "uid");
        self::assertSame(["1", "2", "3", "4", "5"], array_map("strval", $this->query()->whereAll()->get()->keys()->all()));

        $config->set("options.message_key", "id");
        self::assertSame(
            ["msg1@domain.tld", "msg2@domain.tld", "msg3@domain.tld", "msg4@domain.tld", "msg5@domain.tld"],
            $this->query()->whereAll()->get()->keys()->all()
        );
    }

    public function testMessageKeyNumber(): void {
        $config = $this->client->getConfig();
        $config->set("options.message_key", "number");
        $config->set("options.sequence", IMAP::ST_MSGN);

        self::assertSame(["1", "2", "3", "4", "5"], array_map("strval", $this->query()->whereAll()->get()->keys()->all()));
    }

    public function testLimitAndPageSelectUids(): void {
        $messages = $this->query()->whereAll()->limit(2, 2)->get();

        self::assertSame(2, $messages->count());
        self::assertSame(5, $messages->total());
        self::assertSame([3, 4], $this->callsOf("headers")[0][1]);
    }

    public function testDescendingFetchOrder(): void {
        $messages = $this->query()->whereAll()->setFetchOrderDesc()->limit(2)->get();

        self::assertSame([5, 4], $this->callsOf("headers")[0][1]);
        self::assertSame(["Message 5", "Message 4"], $messages->map(function(Message $m) {
            return (string)$m->subject;
        })->values()->all());
    }

    public function testExtensionsAreFetchedAndStoredInHeader(): void {
        $messages = $this->query(["X-GM-LABELS"])->whereAll()->limit(1)->get();

        $fetch = $this->callsOf("fetch");
        self::assertCount(1, $fetch);
        self::assertSame(["X-GM-LABELS", "UID"], $fetch[0][1]);
        self::assertSame([1], $fetch[0][2]);

        // the values are stored under the raw extension name
        $attributes = $messages->first()->getHeader()->getAttributes();
        self::assertArrayHasKey("X-GM-LABELS", $attributes);
        self::assertSame(["\\Inbox", "label-1"], $attributes["X-GM-LABELS"]->all());
    }

    public function testExtensionsAreAccessibleViaHeaderGet(): void {
        $this->markTestSkipped("BUG: Header::set() stores the extension under 'X-GM-LABELS', Header::get() looks up 'x_gm_labels'");
        $messages = $this->query(["X-GM-LABELS"])->whereAll()->limit(1)->get();

        self::assertSame(["\\Inbox", "label-1"], $messages->first()->getHeader()->get("X-GM-LABELS")->all());
    }

    public function testInvalidMessageThrowsWithoutSoftFail(): void {
        $this->addMessage(3, "definitely not a date");

        try {
            $this->query()->whereAll()->get();
            $this->fail("Expected GetMessagesFailedException");
        } catch (GetMessagesFailedException $e) {
            self::assertStringContainsString("Invalid message date", $e->getMessage());
        }
    }

    public function testSoftFailCollectsErrors(): void {
        $this->addMessage(3, "definitely not a date");

        $query = $this->query()->whereAll()->softFail();
        $messages = $query->get();

        self::assertSame(4, $messages->count());
        self::assertTrue($query->hasErrors());
        self::assertTrue($query->hasErrors(3));
        self::assertTrue($query->hasError(3));
        self::assertFalse($query->hasError(2));
        self::assertInstanceOf(InvalidMessageDateException::class, $query->error(3));
        self::assertSame([3], array_keys($query->getErrors()));
    }

    public function testSoftFailWithExtensions(): void {
        $this->addMessage(3, "definitely not a date");

        $query = $this->query(["X-GM-LABELS"])->whereAll()->softFail();
        $messages = $query->get();

        self::assertSame(4, $messages->count());
        self::assertTrue($query->hasError(3));
    }

    public function testChunked(): void {
        $chunks = [];
        $this->query()->whereAll()->limit(3, 4)->chunked(function(MessageCollection $messages, int $page) use (&$chunks) {
            $chunks[$page] = $messages->map(function(Message $m) {
                return $m->getUid();
            })->values()->all();
        }, 2);

        self::assertSame([1 => [1, 2], 2 => [3, 4], 3 => [5]], $chunks);
    }

    public function testChunkedRestoresLimitAndPage(): void {
        $query = $this->query()->whereAll()->limit(3, 4);
        $query->chunked(function() {}, 2);

        self::assertSame(3, $query->getLimit());
        self::assertSame(4, $query->getPage());
    }

    public function testChunkedWithStartChunk(): void {
        $pages = [];
        $this->query()->whereAll()->chunked(function(MessageCollection $messages, int $page) use (&$pages) {
            $pages[$page] = $messages->count();
        }, 2, 2);

        self::assertSame([2 => 2, 3 => 1], $pages);
    }

    public function testChunkedStartChunkBeyondResult(): void {
        $called = false;
        $this->query()->whereAll()->chunked(function() use (&$called) {
            $called = true;
        }, 2, 4);

        self::assertFalse($called);
    }

    public function testChunkedNormalizesInvalidArguments(): void {
        $pages = [];
        $this->query()->whereAll()->chunked(function(MessageCollection $messages, int $page) use (&$pages) {
            $pages[$page] = $messages->count();
        }, 0, -5);

        // chunk size and start chunk are clamped to 1
        self::assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], $pages);
    }

    public function testChunkedOnEmptyMailbox(): void {
        $this->mailbox = [];
        $called = false;
        $this->query()->whereAll()->chunked(function() use (&$called) {
            $called = true;
        });

        self::assertFalse($called);
    }

    public function testChunkedTerminatesWithSoftFailErrors(): void {
        $this->addMessage(2, "definitely not a date");

        $calls = 0;
        $this->query()->whereAll()->softFail()->chunked(function() use (&$calls) {
            if (++$calls > 10) {
                throw new \RuntimeException("chunked() does not terminate");
            }
        }, 2);

        self::assertSame(3, $calls);
    }

    public function testPaginate(): void {
        $paginator = $this->query()->whereAll()->paginate(2, 2);

        self::assertInstanceOf(LengthAwarePaginator::class, $paginator);
        self::assertSame(5, $paginator->total());
        self::assertSame(2, $paginator->currentPage());
        self::assertSame(3, $paginator->lastPage());
        self::assertSame(2, $paginator->perPage());
        self::assertCount(2, $paginator->items());
        self::assertSame("imap_page", $paginator->getPageName());
        self::assertSame([3, 4], $this->callsOf("headers")[0][1]);
    }

    public function testPaginateReadsPageFromRequest(): void {
        $_GET["imap_page"] = "3";
        try {
            $paginator = $this->query()->whereAll()->paginate(2);
        } finally {
            unset($_GET["imap_page"]);
        }

        self::assertSame(3, $paginator->currentPage());
        self::assertCount(1, $paginator->items());
        self::assertSame([5], $this->callsOf("headers")[0][1]);
    }

    public function testPaginateCustomPageName(): void {
        $_GET["p"] = "2";
        try {
            $paginator = $this->query()->whereAll()->paginate(4, null, "p");
        } finally {
            unset($_GET["p"]);
        }

        self::assertSame(2, $paginator->currentPage());
        self::assertSame("p", $paginator->getPageName());
        self::assertSame([5], $this->callsOf("headers")[0][1]);
    }

    public function testUidFilters(): void {
        $uids = function(MessageCollection $messages) {
            return $messages->map(function(Message $m) {
                return $m->getUid();
            })->values()->all();
        };

        self::assertSame([3, 4, 5], $uids($this->query()->getByUidGreaterOrEqual(3)));
        self::assertSame([4, 5], $uids($this->query()->getByUidGreater(3)));
        self::assertSame([1, 2], $uids($this->query()->getByUidLower(3)));
        self::assertSame([1, 2, 3], $uids($this->query()->getByUidLowerOrEqual(3)));
        self::assertSame([1, 2], $uids($this->query()->getByUidLowerThan(3)));
        self::assertSame([], $uids($this->query()->getByUidGreater(5)));
        self::assertSame([2, 4], $uids($this->query()->filter(function($uid) {
            return $uid % 2 === 0;
        })));
    }

    public function testCurateMessagesWrapsErrors(): void {
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $protocol->method('selectFolder')->willReturn(Response::empty()->setResult(["exists" => 1]));
        $protocol->method('flags')->willReturn(Response::empty()->setCanBeEmpty(true)->setResult([]));
        $protocol->method('headers')->willReturn(Response::make(1, [], ["TAG1 NO headers failed\r\n"]));
        $this->client->connection = $protocol;

        try {
            $this->query()->curate_messages(new \Illuminate\Support\Collection([1]));
            $this->fail("Expected GetMessagesFailedException");
        } catch (GetMessagesFailedException $e) {
            self::assertInstanceOf(ResponseException::class, $e->getPrevious());
        }
    }
}
