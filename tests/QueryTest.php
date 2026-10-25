<?php
/*
* File: QueryTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the search criteria builder (Query / WhereQuery).
*/

namespace Tests;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\InvalidWhereQueryCriteriaException;
use Webklex\PHPIMAP\Exceptions\MessageSearchValidationException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Query\WhereQuery;

class QueryTest extends TestCase {

    /** @var Client $client */
    protected Client $client;

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
    }

    /**
     * Create a new query instance
     * @param array $extensions
     *
     * @return WhereQuery
     */
    protected function query(array $extensions = []): WhereQuery {
        return new WhereQuery($this->client, $extensions);
    }

    public function testEmptyQueryGeneratesEmptyString(): void {
        $query = $this->query();

        self::assertSame("", $query->generate_query());
        self::assertSame("", $query->getRawQuery());
        self::assertCount(0, $query->getQuery());
    }

    public function testFlagCriteriaWithoutValue(): void {
        $query = $this->query()
            ->whereAll()->whereAnswered()->whereDeleted()->whereNew()->whereOld()
            ->whereRecent()->whereSeen()->whereUnanswered()->whereUndeleted()
            ->whereUnflagged()->whereUnseen();

        self::assertSame(
            "ALL ANSWERED DELETED NEW OLD RECENT SEEN UNANSWERED UNDELETED UNFLAGGED UNSEEN",
            $query->generate_query()
        );
        // getRawQuery returns the last generated query
        self::assertSame($query->generate_query(), $query->getRawQuery());
    }

    public function testStringCriteriaAreQuoted(): void {
        $query = $this->query()
            ->whereBcc("bcc@domain.tld")
            ->whereBody("some body")
            ->whereCc("cc@domain.tld")
            ->whereFrom("from@domain.tld")
            ->whereKeyword("Important")
            ->whereSubject("Hello World")
            ->whereText("lorem")
            ->whereTo("to@domain.tld")
            ->whereUnkeyword("Junk");

        self::assertSame(
            'BCC "bcc@domain.tld" BODY "some body" CC "cc@domain.tld" FROM "from@domain.tld" KEYWORD "Important" '.
            'SUBJECT "Hello World" TEXT "lorem" TO "to@domain.tld" UNKEYWORD "Junk"',
            $query->generate_query()
        );
    }

    public function testNumericValuesAreNotQuoted(): void {
        $query = $this->query()->whereUid(42)->whereSubject("2023");

        self::assertSame('UID 42 SUBJECT 2023', $query->generate_query());
    }

    public function testWhereUidIn(): void {
        $query = $this->query()->whereUidIn([1, 5, 9]);

        self::assertSame('UID "1,5,9"', $query->generate_query());

        $query = $this->query()->whereUidIn([7]);
        self::assertSame('UID 7', $query->generate_query());
    }

    public function testDateCriteriaAcceptCarbonAndStrings(): void {
        $query = $this->query()
            ->whereSince(Carbon::create(2023, 4, 5, 13, 14, 15))
            ->whereBefore("2024-01-31")
            ->whereOn("1 March 2022");

        // default date format of the package config is "d-M-Y"
        self::assertSame('SINCE "05-Apr-2023" BEFORE "31-Jan-2024" ON "01-Mar-2022"', $query->generate_query());
    }

    public function testDateFormatIsTakenFromConfig(): void {
        $this->client->getConfig()->set("date_format", "Y-m-d");
        $query = $this->query()->whereSince(Carbon::create(2023, 4, 5));

        self::assertSame('SINCE "2023-04-05"', $query->generate_query());
    }

    public function testDateMonthNamesAreNotLocalized(): void {
        $locale = Carbon::getLocale();
        Carbon::setLocale("de");
        try {
            $query = $this->query()->whereSince(Carbon::create(2023, 3, 5));
            self::assertSame('SINCE "05-Mar-2023"', $query->generate_query());
        } finally {
            Carbon::setLocale($locale);
        }
    }

    public function testUnescapedSearchDates(): void {
        $this->client->getConfig()->set("options.unescaped_search_dates", true);
        $query = $this->query()
            ->whereSince(Carbon::create(2023, 4, 5))
            ->whereBefore(Carbon::create(2023, 5, 6))
            ->whereSubject("05-Apr-2023");

        self::assertSame('SINCE 05-Apr-2023 BEFORE 06-May-2023 SUBJECT "05-Apr-2023"', $query->generate_query());
    }

    public function testUnescapedSearchDatesAppliesToOn(): void {
        $this->client->getConfig()->set("options.unescaped_search_dates", true);
        $query = $this->query()->whereOn(Carbon::create(2023, 4, 5));

        self::assertSame('ON 05-Apr-2023', $query->generate_query());
    }

    public function testInvalidDateThrowsValidationException(): void {
        $this->expectException(MessageSearchValidationException::class);
        $this->query()->whereSince("this is not a date at all");
    }

    public function testInvalidDateInBeforeAndOn(): void {
        foreach (["whereBefore", "whereOn"] as $method) {
            try {
                $this->query()->$method("foo bar baz");
                $this->fail("$method should reject an invalid date");
            } catch (MessageSearchValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWhereAcceptsArrayNotations(): void {
        $expected = 'FROM "someone@email.tld" SEEN';

        self::assertSame($expected, $this->query()->where([["FROM" => "someone@email.tld"], ["SEEN"]])->generate_query());
        self::assertSame($expected, $this->query()->where(["FROM" => "someone@email.tld"])->where(["SEEN"])->generate_query());
        self::assertSame($expected, $this->query()->where(["FROM" => "someone@email.tld", "SEEN"])->generate_query());
        self::assertSame($expected, $this->query()->where("FROM", "someone@email.tld")->where("SEEN")->generate_query());
    }

    public function testCriteriaAreCaseInsensitiveButKeptAsGiven(): void {
        $query = $this->query()->where("seen")->where("From", "a@b.c");

        self::assertSame('seen From "a@b.c"', $query->generate_query());
    }

    public function testInvalidCriteriaThrows(): void {
        try {
            $this->query()->where("FOOBAR");
            $this->fail("Invalid criteria should throw");
        } catch (InvalidWhereQueryCriteriaException $e) {
            self::assertStringContainsString("FOOBAR", $e->getMessage());
        }

        $this->expectException(InvalidWhereQueryCriteriaException::class);
        $this->query()->where(["SEEN", "LARGER" => 100]);
    }

    public function testCustomCriteriaArePassedThroughUnchanged(): void {
        $query = $this->query()->where("CUSTOM X-GM-RAW has:attachment")->where("custom LARGER 1000");

        self::assertSame('X-GM-RAW has:attachment LARGER 1000', $query->generate_query());
    }

    public function testWhereHeaderAndAliases(): void {
        $query = $this->query()
            ->whereHeader("X-Mailer", "Foo")
            ->whereMessageId("<abc@domain.tld>")
            ->whereInReplyTo("<def@domain.tld>");

        self::assertSame(
            'HEADER "X-Mailer" "Foo" HEADER "Message-ID" "<abc@domain.tld>" HEADER "In-Reply-To" "<def@domain.tld>"',
            $query->generate_query()
        );
    }

    public function testWhereHeaderQuotesValuesWithSpaces(): void {
        $query = $this->query()->whereHeader("Subject", "hello world");

        self::assertSame('HEADER "Subject" "hello world"', $query->generate_query());
    }

    public function testQuotedValuesAreEscaped(): void {
        $query = $this->query()->whereSubject('foo" OR ALL "')->whereFrom('back\\slash');

        self::assertSame('SUBJECT "foo\\" OR ALL \\"" FROM "back\\\\slash"', $query->generate_query());
    }

    public function testLineBreaksCannotBeInjected(): void {
        try {
            $raw = $this->query()->whereSubject("x\r\nA001 DELETE INBOX")->generate_query();
            self::assertStringNotContainsString("\r\n", $raw);
        } catch (InvalidWhereQueryCriteriaException|MessageSearchValidationException) {
            self::assertTrue(true);
        }
    }

    public function testSpamHelpersUseHeaderSearch(): void {
        // HEADER <field-name> <string> (RFC 3501 6.4.4), quoted like whereHeader()
        self::assertSame('HEADER "X-Spam-Flag" "NO"', $this->query()->whereNoXSpam()->generate_query());
        self::assertSame('HEADER "X-Spam-Flag" "YES"', $this->query()->whereIsXSpam()->generate_query());
    }

    public function testWhereLanguage(): void {
        self::assertSame('HEADER "Content-Language" "de"', $this->query()->whereLanguage("de")->generate_query());
        // the value cannot break out of the search key
        self::assertSame('HEADER "Content-Language" "de\\" OR ALL"', $this->query()->whereLanguage('de" OR ALL')->generate_query());
    }

    public function testFlaggedTakesNoArgument(): void {
        self::assertSame('FLAGGED', $this->query()->flagged()->generate_query());
    }

    public function testNotAndLogicalOperators(): void {
        $query = $this->query()->whereNot()->whereSeen();
        self::assertSame('NOT SEEN', $query->generate_query());

        $query = $this->query()->orWhere(function(WhereQuery $q) {
            $q->whereFrom("a@b.c")->whereFrom("d@e.f");
        })->andWhere(function(WhereQuery $q) {
            $q->whereUnseen();
        });
        self::assertSame('OR FROM "a@b.c" FROM "d@e.f" AND UNSEEN', $query->generate_query());

        $query = $this->query()->orWhere()->andWhere();
        self::assertSame('OR AND', $query->generate_query());
    }

    public function testMagicMethodAliases(): void {
        $query = $this->query()
            ->unseen()
            ->from("a@b.c")
            ->subject("Foo")
            ->since(Carbon::create(2020, 1, 2))
            ->messageId("id@host")
            ->inReplyTo("re@host")
            ->text("bar");

        self::assertSame(
            'UNSEEN FROM "a@b.c" SUBJECT "Foo" SINCE "02-Jan-2020" HEADER "Message-ID" "<id@host>" HEADER "In-Reply-To" "<re@host>" TEXT "bar"',
            $query->generate_query()
        );
    }

    public function testMagicMethodNegation(): void {
        $query = $this->query()->notSeen()->not_deleted()->notFrom("spam@domain.tld");

        self::assertSame('NOT SEEN NOT DELETED NOT FROM "spam@domain.tld"', $query->generate_query());
    }

    public function testMagicMethodWithWherePrefix(): void {
        // snake case and "where" prefixed method names are normalized
        $query = $this->query()->where_seen()->WhereUnseen();

        self::assertSame('SEEN UNSEEN', $query->generate_query());
    }

    public function testUnknownMagicMethodThrows(): void {
        try {
            $this->query()->fooBar();
            $this->fail("Unknown method should throw");
        } catch (MethodNotFoundException $e) {
            self::assertStringContainsString("whereFooBar", $e->getMessage());
        }
    }

    public function testWhenAndUnless(): void {
        $query = $this->query()
            ->when(true, function(WhereQuery $q) { $q->whereSeen(); })
            ->when(false, function(WhereQuery $q) { $q->whereDeleted(); }, function(WhereQuery $q) { $q->whereUndeleted(); })
            ->when(0, function(WhereQuery $q) { $q->whereDeleted(); })
            ->unless(false, function(WhereQuery $q) { $q->whereNew(); })
            ->unless(true, function(WhereQuery $q) { $q->whereOld(); }, function(WhereQuery $q) { $q->whereRecent(); })
            ->unless("yes", function(WhereQuery $q) { $q->whereOld(); });

        self::assertSame('SEEN UNDELETED NEW RECENT', $query->generate_query());

        // the callback receives the value and its return value is passed through
        $value = $this->query()->when("abc", function(WhereQuery $q, $v) { return $v; });
        self::assertSame("abc", $value);
    }

    public function testSetQueryAndRawQuery(): void {
        $query = $this->query();
        $query->setQuery([["SEEN"], ["FROM", "a@b.c"], ["SUBJECT", null]]);

        self::assertSame('SEEN FROM "a@b.c" SUBJECT', $query->generate_query());

        $query->setRawQuery("ALL");
        self::assertSame("ALL", $query->getRawQuery());
    }

    public function testAvailableCriteria(): void {
        $criteria = $this->query()->getAvailableCriteria();

        foreach (["OR", "AND", "ALL", "SEEN", "UNSEEN", "SINCE", "BEFORE", "ON", "UID", "NOT"] as $item) {
            self::assertContains($item, $criteria);
        }
    }

    public function testDefaultsFromConfig(): void {
        $query = $this->query();

        // package defaults: fetch FT_PEEK, sequence ST_UID, order asc, soft_fail false
        self::assertSame(IMAP::FT_PEEK, $query->getFetchOptions());
        self::assertSame(IMAP::ST_UID, $query->getSequence());
        self::assertSame("asc", $query->getFetchOrder());
        self::assertFalse($query->getSoftFail());
        self::assertTrue($query->getFetchBody());
        self::assertTrue($query->getFetchFlags());
        self::assertNull($query->getLimit());
        self::assertSame(1, $query->getPage());
        self::assertSame([], $query->getExtensions());
    }

    public function testConfigOverridesDefaults(): void {
        $config = $this->client->getConfig();
        $config->set("options.fetch", IMAP::FT_UID);
        $config->set("options.sequence", IMAP::ST_MSGN);
        $config->set("options.fetch_order", "desc");
        $config->set("options.soft_fail", true);

        $query = $this->query();

        self::assertNull($query->getFetchOptions());
        self::assertSame(IMAP::ST_MSGN, $query->getSequence());
        self::assertSame("desc", $query->getFetchOrder());
        self::assertTrue($query->getSoftFail());
    }

    public function testFetchOptionSetters(): void {
        $query = $this->query();

        self::assertSame(IMAP::FT_UID, $query->markAsRead()->getFetchOptions());
        self::assertSame(IMAP::FT_PEEK, $query->leaveUnread()->getFetchOptions());
        self::assertSame(7, $query->fetchOptions(7)->getFetchOptions());
        self::assertFalse($query->fetchBody(false)->getFetchBody());
        self::assertTrue($query->setFetchBody(true)->getFetchBody());
        self::assertFalse($query->setFetchFlags(false)->getFetchFlags());
        self::assertSame(IMAP::ST_MSGN, $query->setSequence(IMAP::ST_MSGN)->getSequence());
    }

    public function testFetchOrder(): void {
        $query = $this->query();

        self::assertSame("desc", $query->setFetchOrder("DESC")->getFetchOrder());
        // invalid values are ignored
        self::assertSame("desc", $query->setFetchOrder("random")->getFetchOrder());
        self::assertSame("asc", $query->fetchOrder("asc")->getFetchOrder());
        self::assertSame("desc", $query->fetchOrderDesc()->getFetchOrder());
        self::assertSame("asc", $query->fetchOrderAsc()->getFetchOrder());
        self::assertSame("desc", $query->setFetchOrderDesc()->getFetchOrder());
        self::assertSame("asc", $query->setFetchOrderAsc()->getFetchOrder());
    }

    public function testLimitAndPage(): void {
        $query = $this->query();

        $query->limit(10, 3);
        self::assertSame(10, $query->getLimit());
        self::assertSame(3, $query->getPage());

        // invalid pages are ignored
        $query->limit(5, 0);
        self::assertSame(5, $query->getLimit());
        self::assertSame(3, $query->getPage());

        // a limit <= 0 disables the limit
        self::assertNull($query->setLimit(0)->getLimit());
        self::assertNull($query->setLimit(-3)->getLimit());
        self::assertSame(20, $query->setLimit(20)->getLimit());

        self::assertSame(2, $query->setPage(2)->getPage());
    }

    public function testSoftFail(): void {
        $query = $this->query();

        self::assertTrue($query->softFail()->getSoftFail());
        self::assertFalse($query->softFail(false)->getSoftFail());
        self::assertTrue($query->setSoftFail()->getSoftFail());
    }

    public function testErrorsAreEmptyByDefault(): void {
        $query = $this->query();

        self::assertFalse($query->hasErrors());
        self::assertFalse($query->hasErrors(1));
        self::assertFalse($query->hasError(1));
        self::assertSame([], $query->errors());
        self::assertSame([], $query->getErrors());
        self::assertNull($query->error(1));
        self::assertNull($query->getError(1));
    }

    public function testExtensionsAlwaysContainUid(): void {
        self::assertSame(["X-GM-LABELS", "UID"], $this->query(["X-GM-LABELS"])->getExtensions());
        self::assertSame(["UID", "X-GM-MSGID"], $this->query(["UID", "X-GM-MSGID"])->getExtensions());
        self::assertSame([], $this->query()->setExtensions([])->getExtensions());
        self::assertSame(["FOO", "UID"], $this->query()->setExtensions(["FOO"])->getExtensions());
    }

    public function testSetClient(): void {
        $query = $this->query();
        $other = new Client(Config::make());

        self::assertSame($query, $query->setClient($other));
    }
}
