<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * @see https://github.com/Webklex/php-imap/pull/621
 */
class PR619Test extends TestCase
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
        $prop->setAccessible(true);
        $prop->setValue($client, null);

        return $client;
    }

    public function testMakeThrowsTypeErrorWhenActiveFolderIsNull(): void
    {
        $this->expectException(\TypeError::class);

        $client = $this->makeClientWithNullActiveFolder();
        Message::make(1, 0, $client, "Subject: Test\r\n\r\n", '', []);
    }

    public function testMakeSucceedsAfterFix(): void
    {
        $client = $this->makeClientWithNullActiveFolder();

        $message = Message::make(1, 0, $client, "Subject: Test\r\n\r\n", '', []);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertIsString($message->getFolderPath());
        $this->assertNotEmpty($message->getFolderPath());
    }
}
