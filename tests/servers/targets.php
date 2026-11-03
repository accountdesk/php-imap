<?php
/*
* File: targets.php
* Category: Test configuration
* Created: 24.10.26
* Updated: 24.10.26
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
*  capabilities (name => bool, only where the server differs from the defaults in
*  Tests\support\ImapTargets::CAPABILITIES - the live tests skip what a server lacks, with the reason),
*  notes (server quirks that matter for the tests).
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
        "capabilities"   => [],
        "notes"          => "",
    ], $extra);
};

return [
    "dovecot23"     => $docker("dovecot23", "dovecot23", 143, 21001, [
        "delimiter"    => "/",
        "capabilities" => ["quota" => true],
        "notes"        => "Dovecot 2.3.21.1, sdbox storage, quota plugin (count backend). Sends BYE and disconnects when the "
            . "selected mailbox is deleted. Stores bare LF verbatim but counts it as CRLF in RFC822.SIZE.",
    ]),
    "dovecot23-ssl" => $docker("dovecot23-ssl", "dovecot23", 993, 21002, [
        "encryption"   => "ssl",
        "delimiter"    => "/",
        "capabilities" => ["quota" => true],
        "notes"        => "Same server as dovecot23, implicit TLS with the image's self-signed certificate.",
    ]),
    "dovecot24"     => $docker("dovecot24", "dovecot24", 31143, 21003, [
        "delimiter"    => "/",
        "capabilities" => ["quota" => true],
        "notes"        => "Dovecot 2.4.5 (rootless image: ports 31143/31993), maildir + index layout, IMAP4rev2.",
    ]),
    "dovecot24-ssl" => $docker("dovecot24-ssl", "dovecot24", 31993, 21004, [
        "encryption"   => "ssl",
        "delimiter"    => "/",
        "capabilities" => ["quota" => true],
        "notes"        => "Same server as dovecot24, implicit TLS with the image's self-signed certificate.",
    ]),
    "greenmail"     => $docker("greenmail", "greenmail", 3143, 21005, [
        "delimiter"    => ".",
        "capabilities" => [
            "id"                      => false, // BAD Invalid command, not announced
            "children"                => false, // LIST without \HasChildren / \HasNoChildren
            "search_on_date"          => false, // SEARCH ON / SENTON <today> find nothing, SINCE works
            "list_inner_wildcard"     => false, // LIST "" "x.%%" -> NO Wildcard characters ... only handled as the last character
        ],
        "notes"        => "GreenMail 2.1.14 (in-memory test server). Repeats the current EXISTS after every DONE. "
            . "Stores messages verbatim (bare LF stays bare LF), RFC822.HEADER lacks the empty line ending the header.",
    ]),
    "cyrus"         => $docker("cyrus", "cyrus", 143, 21006, [
        "username"     => "test",
        "delimiter"    => ".",
        "capabilities" => [
            "special_chars_in_folder_names" => false, // NO Invalid mailbox name for \ " % | ! < >
        ],
        "notes"        => "Cyrus IMAP 3.6.1 (Debian package), traditional namespace (altnamespace: no), "
            . "INBOX auto-created on first login. LIST returns INBOX as an unquoted atom. Rejects APPEND with "
            . "bare LF (NO Message contains bare newlines).",
    ]),
    "stalwart"      => $docker("stalwart", "stalwart", 143, 21007, [
        "delimiter"    => "/",
        "capabilities" => [
            "children"                => false, // announces CHILDREN, but LIST has no \HasChildren / \HasNoChildren
            "search_message_id_header" => false, // SEARCH HEADER Message-ID / In-Reply-To never matches (Subject/From do)
        ],
        "notes"        => "Stalwart 0.16.24, provisioned via its JMAP management API on every start. "
            . "IMAP4rev2; default folders 'Sent Items', 'Deleted Items', 'Junk Mail'. Stores messages verbatim "
            . "(bare LF), auto-subscribes new folders (SUBSCRIBE again / UNSUBSCRIBE again -> NO), "
            . "FETCH RFC822.TEXT returns the whole message (BODY[TEXT] is correct), APPEND without date takes the "
            . "internal date from the first Received header, IDLE timeout 30 min.",
    ]),
    "james"         => $docker("james", "james", 143, 21008, [
        "delimiter"    => ".",
        "capabilities" => [
            "search_quoted_date"            => false, // UID SEARCH SINCE "23-Oct-2026" -> BAD Illegal arguments
            "special_chars_in_folder_names" => false, // NO CREATE processing failed for "%"
        ],
        "notes"        => "Apache James 3.9.1 (demo image, JPA/Derby). Rejects commands pipelined behind LOGIN. "
            . "IDLE heartbeat every 120s. Stores messages verbatim (bare LF), lists \\Recent in FLAGS.",
    ]),
    "courier"       => $docker("courier", "courier", 143, 21009, [
        "username"     => "test",
        "delimiter"    => ".",
        "capabilities" => [
            "id"                           => false, // NO Error in IMAP command, not announced
            "uidnext_in_select"            => false, // SELECT/EXAMINE send no UIDNEXT (STATUS does)
            "search_subject_envelope_only" => false, // SEARCH SUBJECT also matches attached message/rfc822 parts
        ],
        "notes"        => "Courier IMAP 5.0.13 (Debian package), Maildir; folders live below INBOX. "
            . "Appends CRLF to messages that do not end with a line break.",
    ]),
];
