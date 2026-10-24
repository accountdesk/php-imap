<?php
/*
* File: ProtocolTest.php
* Category: Test
*
* Description:
*  The connection settings of Protocol (shared by ImapProtocol): certificate validation, SSL context
*  options, proxy, crypto method, timeouts, UID keys / cache and the stream helpers.
*/

namespace Tests;

use ReflectionMethod;
use Tests\support\ScriptedServerTestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Protocol;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\IMAP;

class ProtocolTest extends ScriptedServerTestCase {

    /**
     * Stream context options Protocol builds for a transport
     * @param Protocol $protocol
     * @param string $transport
     *
     * @return array
     */
    protected function socketOptions(Protocol $protocol, string $transport = "tcp"): array {
        $method = new ReflectionMethod(Protocol::class, "defaultSocketOptions");
        $method->setAccessible(true);
        return $method->invoke($protocol, $transport);
    }

    public function testCryptoMethod(): void {
        // TLS client mask plus TLS 1.2 and 1.3 - never pinned to a single version
        $method = (new ImapProtocol(Config::make()))->getCryptoMethod();
        self::assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $method & STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        if (defined("STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT")) {
            self::assertSame(STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $method & STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        }
    }

    public function testCertValidation(): void {
        $protocol = new ImapProtocol(Config::make());
        self::assertTrue($protocol->getCertValidation());

        self::assertSame($protocol, $protocol->disableCertValidation());
        self::assertFalse($protocol->getCertValidation());
        self::assertSame($protocol, $protocol->enableCertValidation());
        self::assertTrue($protocol->getCertValidation());
        $protocol->setCertValidation(0);
        self::assertFalse($protocol->getCertValidation());
    }

    public function testSocketOptionsWithoutEncryptionAndProxy(): void {
        $protocol = new ImapProtocol(Config::make(), true, false);
        $protocol->setSslOptions(["cafile" => "/etc/ssl/ca.pem"]);

        self::assertSame([], $this->socketOptions($protocol));
    }

    public function testSocketOptionsPreferValidateCertOverSslOptions(): void {
        $protocol = new ImapProtocol(Config::make(), false, "ssl");
        $protocol->setSslOptions(["verify_peer" => true, "verify_peer_name" => true, "cafile" => "/etc/ssl/ca.pem"]);

        self::assertEquals(["ssl" => [
            "verify_peer"      => false,
            "verify_peer_name" => false,
            "cafile"           => "/etc/ssl/ca.pem",
        ]], $this->socketOptions($protocol, "ssl"));

        $protocol = new ImapProtocol(Config::make(), true, "starttls");
        self::assertEquals(["ssl" => ["verify_peer" => true, "verify_peer_name" => true]], $this->socketOptions($protocol));
    }

    public function testProxySettings(): void {
        $protocol = new ImapProtocol(Config::make());
        self::assertSame(["socket" => null, "request_fulluri" => false, "username" => null, "password" => null], $protocol->getProxy());

        self::assertSame($protocol, $protocol->setProxy(["socket" => "tcp://proxy.local:8080", "unknown" => "ignored"]));
        self::assertSame(["socket" => "tcp://proxy.local:8080", "request_fulluri" => false, "username" => null, "password" => null], $protocol->getProxy());
        self::assertSame(["tcp" => ["proxy" => "tcp://proxy.local:8080", "request_fulluri" => false]], $this->socketOptions($protocol));

        $protocol->setProxy(["request_fulluri" => true, "username" => "proxy-user", "password" => "proxy-pass"]);
        self::assertSame(["ssl" => [
            "proxy"           => "tcp://proxy.local:8080",
            "request_fulluri" => true,
            "header"          => ["Proxy-Authorization: Basic " . base64_encode("proxy-user:proxy-pass")],
        ]], $this->socketOptions($protocol, "ssl"));
    }

    /**
     * The "proxy" context option exists for the http wrapper only - tcp:// and ssl:// streams ignore it.
     */
    public function testConnectionUsesTheProxy(): void {
        $this->markTestSkipped("BUG: the proxy settings are passed as tcp/ssl context options, which PHP ignores - the connection bypasses the proxy");

        $port = $this->startServer([[]]);
        $protocol = $this->protocol();
        $protocol->setProxy(["socket" => "tcp://127.0.0.1:" . self::unusedPort()]);

        $this->expectException(ConnectionFailedException::class);
        @$protocol->connect("127.0.0.1", $port);
    }

    public function testCreateStream(): void {
        $port = $this->startServer([[]]);
        $protocol = $this->protocol();

        $stream = $protocol->createStream("tcp", "127.0.0.1", $port, 2);

        self::assertIsResource($stream);
        self::assertSame(["timed_out" => false, "blocked" => true, "eof" => false], array_intersect_key(stream_get_meta_data($stream), ["timed_out" => 0, "blocked" => 0, "eof" => 0]));
        fclose($stream);
    }

    public function testCreateStreamFailure(): void {
        $protocol = new ImapProtocol(Config::make());

        try {
            @$protocol->createStream("tcp", "127.0.0.1", self::unusedPort(), 1);
            self::fail("ConnectionFailedException expected");
        } catch (ConnectionFailedException $e) {
            self::assertNotSame("", $e->getMessage());
            self::assertGreaterThan(0, $e->getCode());
        }
    }

    public function testConnectionTimeout(): void {
        $protocol = new ImapProtocol(Config::make());
        self::assertSame(30, $protocol->getConnectionTimeout());
        self::assertSame($protocol, $protocol->setConnectionTimeout(5));
        self::assertSame(5, $protocol->getConnectionTimeout());
    }

    public function testEncryption(): void {
        $protocol = new ImapProtocol(Config::make(), true, "tls");
        self::assertSame("tls", $protocol->getEncryption());
        $protocol->setEncryption("");
        self::assertSame("", $protocol->getEncryption());
    }

    public function testUidKeyAndCommand(): void {
        $protocol = new ImapProtocol(Config::make());

        self::assertSame("UID", $protocol->getUIDKey(IMAP::ST_UID));
        self::assertSame("UID", $protocol->getUIDKey(IMAP::FT_UID));
        self::assertSame("UID", $protocol->getUIDKey("1"));
        self::assertSame("", $protocol->getUIDKey(IMAP::ST_MSGN));
        self::assertSame("", $protocol->getUIDKey(""));
        self::assertSame("X-GM", $protocol->getUIDKey("X-GM"));

        self::assertSame("UID FETCH", $protocol->buildUIDCommand("FETCH", IMAP::ST_UID));
        self::assertSame("FETCH", $protocol->buildUIDCommand("FETCH", IMAP::ST_MSGN));
    }

    public function testUidCache(): void {
        $protocol = new ImapProtocol(Config::make());

        // a filled cache answers without a connection
        $protocol->setUidCache(["4", 7, "9"]);
        self::assertSame([1 => 4, 2 => 7, 3 => 9], $protocol->getUid()->validatedData());
        self::assertSame(9, $protocol->getUid(3)->validatedData());
        self::assertSame(2, $protocol->getMessageNumber("7")->validatedData());

        $cache = new \ReflectionProperty(Protocol::class, "uid_cache");
        $cache->setAccessible(true);

        // reset() clears the cache together with the stream
        $protocol->reset();
        self::assertSame([], $cache->getValue($protocol));
        self::assertNull($protocol->getStream());

        $protocol->setUidCache([1]);
        self::assertSame([1 => 1], $cache->getValue($protocol));
        $protocol->setUidCache(null);
        self::assertSame([], $cache->getValue($protocol));
    }

    public function testMetaAndStreamWithoutConnection(): void {
        $config = Config::make();
        $protocol = new ImapProtocol($config);

        self::assertFalse($protocol->getStream());
        self::assertSame($config, $protocol->getConfig());
        self::assertSame([
            "crypto"       => ["protocol" => "", "cipher_name" => "", "cipher_bits" => 0, "cipher_version" => ""],
            "timed_out"    => true,
            "blocked"      => true,
            "eof"          => true,
            "stream_type"  => "tcp_socket/unknown",
            "mode"         => "c",
            "unread_bytes" => 0,
            "seekable"     => false,
        ], $protocol->meta());
        // without a stream logout() does not send anything
        self::assertSame([], $protocol->logout()->getCommands());
    }

    public function testMetaOfAConnectedStream(): void {
        $protocol = $this->connectedProtocol([]);

        $meta = $protocol->meta();

        self::assertFalse($meta["timed_out"]);
        self::assertFalse($meta["eof"]);
        self::assertSame("tcp_socket/ssl", $meta["stream_type"]);
    }
}
