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
        "notes"          => "",
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
            self::$targets = array_map(static fn(array $target) => array_merge(self::DEFAULTS, $target), $targets);
        }
        return self::$targets;
    }
}
