<?php
/*
* File: ImapProtocolTest.php
* Category: -
* Author: M.Goldenbaum
* Created: 28.12.22 18:11
* Updated: -
*
* Description:
*  -
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class ImapProtocolTest extends TestCase {

    /** @var Config $config */
    protected Config $config;

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public function setUp(): void {
        $this->config = Config::make();
    }


    /**
     * ImapProtocol test
     *
     * @return void
     */
    public function testImapProtocol(): void {

        $protocol = new ImapProtocol($this->config, false);
        self::assertSame(false, $protocol->getCertValidation());
        self::assertSame("", $protocol->getEncryption());

        $protocol->setCertValidation(true);
        $protocol->setEncryption("ssl");

        self::assertSame(true, $protocol->getCertValidation());
        self::assertSame("ssl", $protocol->getEncryption());

        $protocol->setSslOptions([
            'verify_peer' => true,
            'cafile' => '/dummy/path/for/testing',
            'peer_fingerprint' => ['md5' => 40],
        ]);

        self::assertSame([
            'verify_peer' => true,
            'cafile' => '/dummy/path/for/testing',
            'peer_fingerprint' => ['md5' => 40],
        ], $protocol->getSslOptions());
    }

    /**
     * Decode a single response line with the protected tokenizer
     * @param string $line
     *
     * @return array
     */
    protected function decodeLine(string $line): array {
        $protocol = new class($this->config, false) extends ImapProtocol {
            public function decode(string $line): array {
                return $this->decodeLine(Response::empty(), $line);
            }
        };
        return $protocol->decode($line);
    }

    /**
     * A quoted string directly followed by a closing parenthesis must stay one token
     *
     * @return void
     */
    public function testDecodeLineQuotedStringBeforeClosingParenthesis(): void {
        self::assertSame(
            ['1', 'FETCH', ['UID', '1', 'INTERNALDATE', '22-Oct-2026 10:00:00 +0000']],
            $this->decodeLine('1 FETCH (UID 1 INTERNALDATE "22-Oct-2026 10:00:00 +0000")')
        );

        // closing more than one list at once
        self::assertSame(
            [['a', ['b', 'c d']], 'e'],
            $this->decodeLine('(a (b "c d")) e')
        );

        // a quoted string followed by a space keeps working as before
        self::assertSame(
            [['UID', '1', 'INTERNALDATE', '22-Oct-2026 10:00:00 +0000', 'RFC822.SIZE', '42']],
            $this->decodeLine('(UID 1 INTERNALDATE "22-Oct-2026 10:00:00 +0000" RFC822.SIZE 42)')
        );

        // an escaped quote followed by a closing parenthesis does not end the string
        self::assertSame(
            [['Test (\\"quoted\\") x']],
            $this->decodeLine('("Test (\\"quoted\\") x")')
        );
    }
}
