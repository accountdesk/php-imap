<?php

namespace Tests\issues;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * @see https://github.com/Webklex/php-imap/pull/621
 */
class PR621Test extends TestCase
{
    private function makeClientWithNullActiveFolder(): Client
    {
        $config = Config::make([
            'accounts' => [
                'default' => [
                    'host'       => 'localhost',
                    'protocol'   => 'imap',
                    'encryption' => 'ssl',
                    'username'   => 'foo@example.com',
                    'password'   => 'secret',
                ],
            ],
        ]);

        $client = new Client($config);

        $protocol = $this->createStub(ImapProtocol::class);
        $protocol->method('connected')->willReturn(true);
        $client->connection = $protocol;

        // Simulate the state after an implicit reconnect:
        // Client::connect() always calls disconnect() first,
        // and disconnect() resets active_folder to null.
        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('active_folder');
        $prop->setValue($client, null);

        return $client;
    }

    public function testFolderPathFallsBackToInboxWhenActiveFolderIsNull(): void
    {
        // Regression: after an implicit reconnect the client has no active folder,
        // so Client::getFolderPath() returns null. Config::get() previously returned
        // a partially-resolved intermediate array for a missing dotted key, which
        // broke Message::setFolderPath()'s INBOX fallback (Array-to-string cast).
        $client = $this->makeClientWithNullActiveFolder();
        $this->assertNull($client->getFolderPath());

        $message = Message::fromString("Subject: Test\r\n\r\nHello");
        $message->setFolderPath($client->getFolderPath());

        $this->assertSame('INBOX', $message->getFolderPath());
    }
}
