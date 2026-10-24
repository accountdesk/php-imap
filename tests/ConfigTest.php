<?php
/*
* File: ConfigTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for Config and ClientManager.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Decoder\AttachmentDecoder;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;
use Webklex\PHPIMAP\Decoder\MessageDecoder;
use Webklex\PHPIMAP\Exceptions\DecoderNotFoundException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;

class ConfigTest extends TestCase {

    public function testGetWithDotNotation(): void {
        $config = new Config(["a" => ["b" => ["c" => "value"]], "flag" => false, "zero" => 0, "null" => null]);

        self::assertSame("value", $config->get("a.b.c"));
        self::assertSame(["c" => "value"], $config->get("a.b"));
        self::assertFalse($config->get("flag", true));
        self::assertSame(0, $config->get("zero", 5));
        self::assertSame("default", $config->get("null", "default"));
        self::assertSame("default", $config->get("missing", "default"));
        self::assertNull($config->get("missing"));
        self::assertSame(["a" => ["b" => ["c" => "value"]], "flag" => false, "zero" => 0, "null" => null], $config->all());
    }

    public function testGetReturnsDefaultForMissingNestedKey(): void {
        $config = new Config(["a" => ["b" => 1]]);

        self::assertSame("default", $config->get("a.missing", "default"));
        self::assertNull($config->get("a.b.c"));
    }

    public function testSet(): void {
        $config = new Config(["a" => ["b" => 1, "c" => 2]]);

        $config->set("x.y.z", "new");
        self::assertSame("new", $config->get("x.y.z"));

        // arrays are merged into existing arrays
        $config->set("a", ["c" => 3, "d" => 4]);
        self::assertSame(["b" => 1, "c" => 3, "d" => 4], $config->get("a"));

        // scalars replace existing values
        $config->set("a.b", "scalar");
        self::assertSame("scalar", $config->get("a.b"));
        $config->set("a", "flat");
        self::assertSame("flat", $config->get("a"));
    }

    /**
     * A list replaces the existing list - array_merge() would append it (["attachment", "inline", "attachment"])
     */
    public function testSetReplacesLists(): void {
        $config = Config::make();

        $config->set("options.dispositions", ["attachment"]);
        self::assertSame(["attachment"], $config->get("options.dispositions"));

        $config->set("accounts.default.extensions", ["X-GM-LABELS"]);
        $config->set("accounts.default.extensions", ["X-GM-MSGID"]);
        self::assertSame(["X-GM-MSGID"], $config->get("accounts.default.extensions"));
    }

    /**
     * Setting below a scalar value replaces it instead of failing ("Cannot use a scalar value as an array")
     */
    public function testSetBelowAScalar(): void {
        $config = new Config(["options" => ["delimiter" => "/"]]);

        $config->set("options.delimiter.x", 1);
        self::assertSame(["x" => 1], $config->get("options.delimiter"));
    }

    /**
     * A list given where the defaults have named keys must not raise "Undefined array key"
     */
    public function testMakeWithListForAssociativeDefaults(): void {
        $config = Config::make(["options" => ["common_folders" => ["INBOX/Trash", "INBOX/Sent", "INBOX/Spam", "INBOX/Drafts", "INBOX/Archive", "INBOX/Other"]]]);

        self::assertSame("INBOX/Trash", $config->get("options.common_folders.trash"));
        self::assertContains("INBOX/Other", $config->get("options.common_folders"));
    }

    public function testDefaultAccount(): void {
        $config = new Config();
        self::assertSame("default", $config->getDefaultAccount());

        $config->setDefaultAccount("gmail");
        self::assertSame("gmail", $config->getDefaultAccount());
        self::assertSame("gmail", $config->get("default"));
    }

    public function testMakeMergesPackageDefaults(): void {
        $config = Config::make([
            "options" => ["fetch_order" => "desc", "dispositions" => ["attachment"]],
            "accounts" => [
                "default" => ["host" => "imap.example.com"],
                "other"   => ["host" => "other.example.com", "port" => 143],
            ],
        ]);

        self::assertSame("desc", $config->get("options.fetch_order"));
        // untouched defaults are kept
        self::assertSame(IMAP::FT_PEEK, $config->get("options.fetch"));
        self::assertSame("d-M-Y", $config->get("date_format"));
        // lists are replaced instead of merged
        self::assertSame(["attachment"], $config->get("options.dispositions"));

        // every account inherits the default account and the package defaults
        self::assertSame("imap.example.com", $config->get("accounts.default.host"));
        self::assertSame(993, $config->get("accounts.default.port"));
        self::assertSame("other.example.com", $config->get("accounts.other.host"));
        self::assertSame(143, $config->get("accounts.other.port"));
        self::assertSame("RFC822", $config->get("accounts.other.rfc"));
    }

    public function testMakeWithNonDefaultAccountName(): void {
        $config = Config::make([
            "default"  => "work",
            "accounts" => [
                "work" => ["host" => "work.example.com", "username" => "me"],
                "home" => ["host" => "home.example.com"],
            ],
        ]);

        self::assertSame("work", $config->getDefaultAccount());
        // accounts inherit the configured default account
        self::assertSame("me", $config->get("accounts.home.username"));
        self::assertSame("home.example.com", $config->get("accounts.home.host"));
    }

    public function testMakeFromFile(): void {
        $file = tempnam(sys_get_temp_dir(), "php-imap-config");
        file_put_contents($file, '<?php return ["date_format" => "Y-m-d", "options" => ["debug" => true]];');
        try {
            $config = Config::make($file);
            self::assertSame("Y-m-d", $config->get("date_format"));
            self::assertTrue($config->get("options.debug"));
            self::assertSame("asc", $config->get("options.fetch_order"));

            $manager = new ClientManager($file);
            self::assertSame("Y-m-d", $manager->getConfig()->get("date_format"));
        } finally {
            unlink($file);
        }
    }

    public function testGetDecoder(): void {
        $config = Config::make();

        self::assertInstanceOf(HeaderDecoder::class, $config->getDecoder("header"));
        self::assertInstanceOf(MessageDecoder::class, $config->getDecoder("message"));
        self::assertInstanceOf(AttachmentDecoder::class, $config->getDecoder("attachment"));
        self::assertSame("utf-8", $config->getDecoder("header")->getOptions()["header"]);

        $config->set("decoding.options", ["header" => "iconv"]);
        self::assertSame("iconv", $config->getDecoder("header")->getOptions()["header"]);
    }

    public function testUnknownDecoderThrows(): void {
        $this->expectException(DecoderNotFoundException::class);
        Config::make()->getDecoder("unknown");
    }

    public function testMissingDecoderClassThrows(): void {
        $config = Config::make();
        $config->set("decoding.decoder", ["header" => "Not\\Existing\\Decoder"]);

        $this->expectException(DecoderNotFoundException::class);
        $config->getDecoder("header");
    }

    public function testGetMask(): void {
        $config = Config::make();

        self::assertSame(MessageMask::class, $config->getMask("message"));
        self::assertSame(AttachmentMask::class, $config->getMask("attachment"));
        self::assertNull($config->getMask("unknown"));

        $config->set("masks", ["message" => "Not\\Existing\\Mask"]);
        self::assertNull($config->getMask("message"));
    }

    public function testGetClientConfig(): void {
        $config = Config::make([
            "accounts" => [
                "default" => ["host" => "default.example.com"],
                "other"   => ["host" => "other.example.com"],
            ],
        ]);

        foreach ([null, "null", ""] as $name) {
            $client_config = $config->getClientConfig($name);
            self::assertSame("default", $client_config->getDefaultAccount());
            self::assertSame(["default"], array_keys($client_config->get("accounts")));
            self::assertSame("default.example.com", $client_config->get("accounts.default.host"));
        }

        $client_config = $config->getClientConfig("other");
        self::assertSame("other", $client_config->getDefaultAccount());
        self::assertSame(["other"], array_keys($client_config->get("accounts")));
        self::assertSame("other.example.com", $client_config->get("accounts.other.host"));

        // the original config is not modified
        self::assertSame(["default", "other"], array_keys($config->get("accounts")));
    }

    public function testGetClientConfigForUnknownAccount(): void {
        $config = Config::make(["accounts" => ["default" => ["host" => "default.example.com"]]]);

        // unknown accounts fall back to the default account settings
        $client_config = $config->getClientConfig("unknown");
        self::assertSame("default.example.com", $client_config->get("accounts.unknown.host"));
    }

    public function testClientManagerResolvesAndCachesAccounts(): void {
        $manager = new ClientManager([
            "accounts" => [
                "default" => ["host" => "default.example.com"],
                "other"   => ["host" => "other.example.com"],
            ],
        ]);

        $default = $manager->account();
        self::assertInstanceOf(Client::class, $default);
        self::assertSame("default.example.com", $default->host);
        self::assertSame($default, $manager->account("default"));
        self::assertSame("other.example.com", $manager->account("other")->host);
        self::assertNotSame($default, $manager->account("other"));
    }

    public function testClientManagerForwardsCallsToDefaultAccount(): void {
        $manager = new ClientManager(["accounts" => ["default" => ["host" => "default.example.com"]]]);

        // __call forwards unknown methods to the default client
        self::assertSame(MessageMask::class, $manager->getDefaultMessageMask());
        self::assertSame(AttachmentMask::class, $manager->getDefaultAttachmentMask());
        self::assertSame("default.example.com", $manager->getAccountConfig()["host"] ?? null);
    }

    public function testClientManagerMake(): void {
        $manager = new ClientManager(new Config(Config::make()->all()));
        $client = $manager->make(["host" => "made.example.com", "port" => 143]);

        self::assertSame("made.example.com", $client->host);
        self::assertSame(143, $client->port);
        // defaults of the package are applied
        self::assertSame("imap", $client->protocol);
    }
}
