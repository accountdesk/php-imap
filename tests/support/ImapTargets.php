<?php
/*
* File: ImapTargets.php
* Category: Test support
* Created: 24.10.26
* Updated: -
*
* Description:
*  Registry of real IMAP servers the live tests can run against: the docker server matrix
*  (tests/servers/targets.php) plus optional provider accounts (tests/servers/targets.local.php, gitignored).
*  Which targets are active is selected with the environment variable IMAP_TARGETS.
*/

namespace Tests\support;

use InvalidArgumentException;

/**
 * Class ImapTargets
 *
 * IMAP_TARGETS: comma separated target names, "docker" (all docker targets), "all" (every target)
 * or empty (no target, live tests skip).
 *
 * Usage:
 *   $manager = new ClientManager(["accounts" => ["default" => ImapTargets::account("dovecot23")]]);
 *
 * @package Tests\support
 */
final class ImapTargets {

    /**
     * Keys every target has, with their defaults
     */
    private const DEFAULTS = [
        "host"           => "localhost",
        "port"           => 993,
        "encryption"     => "ssl",
        "validate_cert"  => true,
        "username"       => "",
        "password"       => "",
        "authentication" => null,
        "kind"           => "provider",
        "proxy"          => null,
        "delimiter"      => null,
        "quota"          => false,
        "capabilities"   => [],
        "notes"          => "",
    ];

    /**
     * Server capabilities the live tests check with ImapTargets::supports() (LiveMailboxTestCase::requiresCapability()),
     * with the default for targets that do not list them: a target only names what differs. A test whose
     * capability is missing skips with the reason - tests never skip a whole server.
     */
    public const CAPABILITIES = [
        // ID command (rfc2971)
        "id"                            => true,
        // \HasChildren / \HasNoChildren attributes in LIST responses (rfc3348)
        "children"                      => true,
        // GETQUOTAROOT the way tests/live/ClientTest expects (quota root of INBOX with a storage limit)
        "quota"                         => false,
        // SELECT / EXAMINE report UIDNEXT (required by rfc3501)
        "uidnext_in_select"             => true,
        // SEARCH ON <today> matches messages appended today (internal date)
        "search_on_date"                => true,
        // SEARCH accepts dates as quoted strings (rfc3501: date = date-text / DQUOTE date-text DQUOTE)
        "search_quoted_date"            => true,
        // SEARCH HEADER <field> <string> matches substrings (rfc3501) also in Message-ID / In-Reply-To
        "search_header_substring"       => true,
        // SEARCH SUBJECT only looks at the subject of the message itself (rfc3501: envelope), not at attached messages
        "search_subject_envelope_only"  => true,
        // LIST patterns with a wildcard before the last character ("x.%%" - Client::getFolders() appends % to
        // the parent it is given, also when it recurses into children)
        "list_inner_wildcard"           => true,
        // folder names containing LiveMailboxTestCase::SPECIAL_CHARS (\ " % | ! < > umlauts, CJK, ...)
        "special_chars_in_folder_names" => true,
    ];

    /**
     * Loaded targets, null until first use
     * @var array<string, array>|null $targets
     */
    private static ?array $targets = null;

    /**
     * Get all active targets (environment variable IMAP_TARGETS)
     *
     * @return array<string, array> name => target
     * @throws InvalidArgumentException if IMAP_TARGETS names an unknown target
     */
    public static function enabled(): array {
        $selection = trim((string)getenv("IMAP_TARGETS"));
        if ($selection === "") {
            return [];
        }

        $targets = self::all();
        $enabled = [];
        foreach (array_filter(array_map("trim", explode(",", $selection))) as $name) {
            if ($name === "all") {
                $enabled += $targets;
            } elseif ($name === "docker") {
                $enabled += array_filter($targets, static fn(array $target) => $target["kind"] === "docker");
            } else {
                $enabled[$name] = self::get($name);
            }
        }
        return $enabled;
    }

    /**
     * Get a single target, whether it is enabled or not
     * @param string $name
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public static function get(string $name): array {
        $targets = self::all();
        if (!isset($targets[$name])) {
            throw new InvalidArgumentException(sprintf(
                "Unknown IMAP target '%s' (known: %s)", $name, implode(", ", array_keys($targets))
            ));
        }
        return $targets[$name];
    }

    /**
     * Check whether a target has a capability (see ImapTargets::CAPABILITIES)
     * @param string $name target name
     * @param string $capability
     *
     * @return bool
     * @throws InvalidArgumentException for unknown targets or capabilities (a typo must not silently skip tests)
     */
    public static function supports(string $name, string $capability): bool {
        if (!array_key_exists($capability, self::CAPABILITIES)) {
            throw new InvalidArgumentException(sprintf(
                "Unknown capability '%s' (known: %s)", $capability, implode(", ", array_keys(self::CAPABILITIES))
            ));
        }
        return (bool)(self::get($name)["capabilities"][$capability] ?? self::CAPABILITIES[$capability]);
    }

    /**
     * Get the ClientManager account configuration of a target (the value of "accounts" => ["x" => ...])
     * @param string $name
     * @param bool $via_proxy connect through the target's Toxiproxy proxy instead of directly
     *
     * @return array
     * @throws InvalidArgumentException if $via_proxy is requested for a target without proxy
     */
    public static function account(string $name, bool $via_proxy = false): array {
        $target = self::get($name);
        $host = $target["host"];
        $port = $target["port"];
        if ($via_proxy) {
            if (empty($target["proxy"])) {
                throw new InvalidArgumentException(sprintf("IMAP target '%s' has no Toxiproxy proxy", $name));
            }
            $host = $target["proxy"]["host"];
            $port = $target["proxy"]["port"];
        }

        return [
            "host"           => $host,
            "port"           => $port,
            "protocol"       => "imap",
            "encryption"     => $target["encryption"],
            "validate_cert"  => $target["validate_cert"],
            "username"       => $target["username"],
            "password"       => $target["password"],
            "authentication" => $target["authentication"],
        ];
    }

    /**
     * Load targets.php and, if present, targets.local.php
     *
     * @return array<string, array>
     */
    private static function all(): array {
        if (self::$targets === null) {
            $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . "servers" . DIRECTORY_SEPARATOR;
            $targets = require $dir . "targets.php";
            if (is_file($dir . "targets.local.php")) {
                $targets = array_merge($targets, require $dir . "targets.local.php");
            }
            self::$targets = array_map(static function(array $target): array {
                $target = array_merge(self::DEFAULTS, $target);
                // "quota" predates the capability list and is kept for targets.local.php files
                $target["capabilities"] += ["quota" => (bool)$target["quota"]];
                $target["quota"] = (bool)$target["capabilities"]["quota"];
                return $target;
            }, $targets);
        }
        return self::$targets;
    }
}
