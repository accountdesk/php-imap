<?php
/*
* File: Toxiproxy.php
* Category: Test support
* Created: 24.10.26
* Updated: -
*
* Description:
*  Minimal client for the Toxiproxy HTTP API (https://github.com/Shopify/toxiproxy), used by live tests
*  to cut connections to an IMAP target (disable) or let them go silently dead (timeout toxic).
*  Works without ext-curl (stream contexts only).
*/

namespace Tests\support;

use RuntimeException;

/**
 * Class Toxiproxy
 *
 * API URL: constructor argument, else environment variable TOXIPROXY_API, else http://toxiproxy:8474.
 * Proxy names are the IMAP target names (tests/servers/toxiproxy/toxiproxy.json).
 *
 * @package Tests\support
 */
final class Toxiproxy {

    /**
     * Default API URL inside the php-imap-servers network
     */
    private const DEFAULT_API = "http://toxiproxy:8474";

    /**
     * API base URL without trailing slash
     * @var string $api
     */
    private string $api;

    /**
     * Toxiproxy constructor.
     * @param string|null $api
     */
    public function __construct(?string $api = null) {
        $this->api = rtrim($api ?? self::defaultApi(), "/");
    }

    /**
     * Is the Toxiproxy API reachable?
     *
     * @return bool
     */
    public static function available(): bool {
        try {
            (new self())->request("GET", "/version", null, 2);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Disable a proxy: closes all existing connections and refuses new ones
     * @param string $proxy
     *
     * @return void
     * @throws RuntimeException
     */
    public function disable(string $proxy): void {
        $this->request("POST", "/proxies/" . rawurlencode($proxy), ["enabled" => false]);
    }

    /**
     * Enable a proxy again
     * @param string $proxy
     *
     * @return void
     * @throws RuntimeException
     */
    public function enable(string $proxy): void {
        $this->request("POST", "/proxies/" . rawurlencode($proxy), ["enabled" => true]);
    }

    /**
     * Add a toxic to a proxy, e.g. addToxic("dovecot23", "timeout", ["timeout" => 0]) to stop all data
     * without closing the connection.
     * @param string $proxy
     * @param string $type latency, bandwidth, slow_close, timeout, reset_peer, slicer, limit_data
     * @param array $attributes type specific attributes
     * @param string $stream "downstream" (server -> client) or "upstream" (client -> server)
     * @param string|null $name defaults to "<type>_<stream>"
     *
     * @return string name of the toxic
     * @throws RuntimeException
     */
    public function addToxic(string $proxy, string $type, array $attributes, string $stream = "downstream", ?string $name = null): string {
        $name = $name ?? $type . "_" . $stream;
        $this->request("POST", "/proxies/" . rawurlencode($proxy) . "/toxics", [
            "name"       => $name,
            "type"       => $type,
            "stream"     => $stream,
            "toxicity"   => 1.0,
            "attributes" => (object)$attributes,
        ]);
        return $name;
    }

    /**
     * Remove a toxic from a proxy
     * @param string $proxy
     * @param string $name
     *
     * @return void
     * @throws RuntimeException
     */
    public function removeToxic(string $proxy, string $name): void {
        $this->request("DELETE", "/proxies/" . rawurlencode($proxy) . "/toxics/" . rawurlencode($name));
    }

    /**
     * Enable all proxies and remove all toxics
     *
     * @return void
     * @throws RuntimeException
     */
    public function reset(): void {
        $this->request("POST", "/reset");
    }

    /**
     * Get the API URL from the environment
     *
     * @return string
     */
    private static function defaultApi(): string {
        $api = getenv("TOXIPROXY_API");
        return $api !== false && $api !== "" ? $api : self::DEFAULT_API;
    }

    /**
     * Send a request to the API
     * @param string $method
     * @param string $path
     * @param array|null $body JSON body
     * @param float $timeout seconds
     *
     * @return mixed decoded JSON response (null for empty responses)
     * @throws RuntimeException on connection errors and non-2xx responses
     */
    private function request(string $method, string $path, ?array $body = null, float $timeout = 10): mixed {
        $headers = ["Accept: application/json", "Connection: close"];
        $content = "";
        if ($body !== null) {
            $content = json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = "Content-Type: application/json";
        }
        $headers[] = "Content-Length: " . strlen($content);

        $context = stream_context_create(["http" => [
            "method"        => $method,
            "header"        => implode("\r\n", $headers),
            "content"       => $content,
            "timeout"       => $timeout,
            "ignore_errors" => true,
        ]]);

        $url = $this->api . $path;
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException(sprintf("Toxiproxy %s %s failed: %s", $method, $url, $error["message"] ?? "no response"));
        }

        /** @var array $http_response_header set by file_get_contents() */
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf("Toxiproxy %s %s: HTTP %d %s", $method, $url, $status, trim($response)));
        }

        return $response === "" ? null : json_decode($response, true);
    }
}
