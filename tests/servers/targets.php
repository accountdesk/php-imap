<?php
/*
* File: targets.php
* Category: Test configuration
* Created: 24.10.26
* Updated: -
*
* Description:
*  IMAP targets of the docker server matrix (tests/servers/docker-compose.yml), read by
*  Tests\support\ImapTargets. Host names are compose service names: tests have to run inside the
*  network php-imap-servers_default (.claude/scripts/imap-servers.sh test|live does that).
*  Real provider accounts go into targets.local.php (gitignored, see targets.local.php.dist).
*
*  Keys: host, port, encryption (false|"ssl"|"tls"|"starttls"), validate_cert, username, password,
*  authentication (null|"oauth"), kind ("docker"|"provider"), proxy (null|["name","host","port"] of the
*  Toxiproxy proxy in front of the target), delimiter (hierarchy separator, null if unknown),
*  quota (server supports GETQUOTAROOT the way tests/live/ClientTest expects), notes.
*/

$password = "matrix-Probe-7319";

$docker = static function(string $name, string $host, int $port, int $proxy_port, array $extra = []) use ($password): array {
    return array_merge([
        "host"           => $host,
        "port"           => $port,
        "encryption"     => false,
        "validate_cert"  => false,
        "username"       => "test@example.local",
        "password"       => $password,
        "authentication" => null,
        "kind"           => "docker",
        "proxy"          => ["name" => $name, "host" => "toxiproxy", "port" => $proxy_port],
        "delimiter"      => null,
        "quota"          => false,
        "notes"          => "",
    ], $extra);
};

return [
    "dovecot23"     => $docker("dovecot23", "dovecot23", 143, 21001, [
        "delimiter" => "/",
        "quota"     => true,
        "notes"     => "Dovecot 2.3.21.1, sdbox storage, quota plugin (count backend). Sends BYE and disconnects when the "
            . "selected mailbox is deleted.",
    ]),
    "dovecot23-ssl" => $docker("dovecot23-ssl", "dovecot23", 993, 21002, [
        "encryption" => "ssl",
        "delimiter"  => "/",
        "quota"      => true,
        "notes"      => "Same server as dovecot23, implicit TLS with the image's self-signed certificate.",
    ]),
    "dovecot24"     => $docker("dovecot24", "dovecot24", 31143, 21003, [
        "delimiter" => "/",
        "quota"     => true,
        "notes"     => "Dovecot 2.4.5 (rootless image: ports 31143/31993), maildir + index layout, IMAP4rev2.",
    ]),
    "dovecot24-ssl" => $docker("dovecot24-ssl", "dovecot24", 31993, 21004, [
        "encryption" => "ssl",
        "delimiter"  => "/",
        "quota"      => true,
        "notes"      => "Same server as dovecot24, implicit TLS with the image's self-signed certificate.",
    ]),
    "greenmail"     => $docker("greenmail", "greenmail", 3143, 21005, [
        "delimiter" => ".",
        "notes"     => "GreenMail 2.1.14 (in-memory test server). Repeats the current EXISTS after every DONE. No ID, no "
            . "CHILDREN flags, stores messages verbatim (bare LF stays bare LF), SEARCH ON <today> finds no freshly appended message.",
    ]),
    "cyrus"         => $docker("cyrus", "cyrus", 143, 21006, [
        "username"  => "test",
        "delimiter" => ".",
        "notes"     => "Cyrus IMAP 3.6.1 (Debian package), traditional namespace (altnamespace: no), "
            . "INBOX auto-created on first login. LIST returns INBOX as an unquoted atom. Rejects APPEND with "
            . "bare LF (NO Message contains bare newlines).",
    ]),
    "stalwart"      => $docker("stalwart", "stalwart", 143, 21007, [
        "delimiter" => "/",
        "notes"     => "Stalwart 0.16.24, provisioned via its JMAP management API on every start. "
            . "IMAP4rev2; default folders 'Sent Items', 'Deleted Items', 'Junk Mail'. Stores messages verbatim "
            . "(bare LF), auto-subscribes new folders (SUBSCRIBE again -> NO), IDLE timeout 30 min.",
    ]),
    "james"         => $docker("james", "james", 143, 21008, [
        "delimiter" => ".",
        "notes"     => "Apache James 3.9.1 (demo image, JPA/Derby). Rejects commands pipelined behind LOGIN. "
            . "IDLE heartbeat every 120s. Stores messages verbatim (bare LF), rejects quoted "
            . "dates in SEARCH (BAD), lists \\Recent in FLAGS.",
    ]),
    "courier"       => $docker("courier", "courier", 143, 21009, [
        "username"  => "test",
        "delimiter" => ".",
        "notes"     => "Courier IMAP 5.0.13 (Debian package), Maildir; folders live below INBOX. SELECT/EXAMINE send no "
            . "UIDNEXT (RFC 3501 violation; STATUS does), no ID extension.",
    ]),
];
