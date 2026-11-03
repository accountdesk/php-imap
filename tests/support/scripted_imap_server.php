<?php
/*
* File: scripted_imap_server.php
* Category: Test support
*
* Description:
*  Scripted fake IMAP server for protocol tests (see ScriptedServerTestCase). It runs as a child
*  process, prints the listening port on the first line of STDOUT and plays one script per accepted
*  connection.
*
*  Usage: php scripted_imap_server.php <script.json> <log_file>
*
*  Script (JSON):
*   {
*     "lifetime": 10,                       // seconds until the server gives up
*     "connections": [                      // one entry per accepted connection, in order
*       {
*         "greeting": "* OK ready",         // null: send nothing
*         "tls": false,                     // true: implicit TLS ("local_cert" / "local_pk" files required)
*         "local_cert": "...", "local_pk": "...",
*         "close_after_greeting": false,
*         "steps": [ step, ... ],           // must be received in this order
*         "auto": [ step, ... ]             // may be received at any time (not consumed), e.g. NOOP
*       }
*     ]
*   }
*
*  Step:
*   {
*     "expect": "LOGIN \"user\" \"secret\"", // without leading "/": the received line without its tag
*                                            // must be equal; with leading "/": a PCRE pattern matched
*                                            // against the complete line (tag included)
*     "reply": ["* CAPABILITY ...", "{tag} OK done"],  // each entry is sent followed by CRLF,
*                                            // {tag} is replaced with the tag of the received line;
*                                            // an entry {"raw": "..."} is sent as is (no CRLF)
*     "delay": 0,                            // milliseconds to wait before replying
*     "close": false,                        // close the connection after the reply
*     "reject_literal": false,               // the line announces a literal: reply instead of "+ OK"
*     "starttls": false                      // enable TLS after the reply (STARTTLS)
*   }
*
*  A line ending with a literal announcement {n} is completed like a real server does: "+ OK" is sent,
*  n bytes and the rest of the line are read, and the complete command (literal inline) is matched.
*
*  Log (one JSON object per line): {"conn": 1, "event": "recv"|"unexpected"|"closed"|"accepted"|"error",
*  "line": "...", "step": <index>|null, "auto": bool, "expected": "..."}. Lines are logged before the
*  reply is sent, so a client that got its reply can rely on the log being complete.
*/

$script = json_decode((string)file_get_contents($argv[1]), true);
$log_file = $argv[2];
if (!is_array($script)) {
    fwrite(STDERR, "invalid script\n");
    exit(1);
}
$connections = $script["connections"] ?? [];
$lifetime = (float)($script["lifetime"] ?? 10);

$log = function (array $entry) use ($log_file): void {
    file_put_contents($log_file, json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
};

$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ":") + 1) . "\n";
flush();

/** @var array<int, resource> $clients */
$clients = [];
/** @var array<int, array{script: array, pos: int}> $state */
$state = [];
$next_id = 1;
$end = microtime(true) + $lifetime;

$send = function ($stream, string $data): void {
    @fwrite($stream, $data);
};

$tls_context = function (array $conn): array {
    return [
        "local_cert"        => $conn["local_cert"] ?? "",
        "local_pk"          => $conn["local_pk"] ?? "",
        "verify_peer"       => false,
        "allow_self_signed" => true,
    ];
};

$close = function (int $id) use (&$clients, &$state, $log): void {
    if (isset($clients[$id])) {
        @fclose($clients[$id]);
        $log(["conn" => $id, "event" => "closed"]);
    }
    unset($clients[$id], $state[$id]);
};

/**
 * Does a step match the received line?
 */
$matches = function (array $step, string $line): bool {
    $expect = (string)($step["expect"] ?? "");
    if ($expect !== "" && $expect[0] === "/") {
        return preg_match($expect, $line) === 1;
    }
    $parts = explode(" ", $line, 2);
    return ($parts[1] ?? "") === $expect;
};

$respond = function (int $id, array $step, string $line) use (&$clients, &$state, $send, $close, $tls_context, $log): void {
    $stream = $clients[$id];
    $tag = explode(" ", $line, 2)[0];
    if (!empty($step["delay"])) {
        usleep((int)$step["delay"] * 1000);
    }
    foreach ($step["reply"] ?? [] as $reply) {
        if (is_array($reply)) {
            $send($stream, str_replace("{tag}", $tag, (string)$reply["raw"]));
        } else {
            $send($stream, str_replace("{tag}", $tag, (string)$reply) . "\r\n");
        }
    }
    if (!empty($step["starttls"])) {
        foreach ($tls_context($state[$id]["script"]) as $key => $value) {
            stream_context_set_option($stream, "ssl", $key, $value);
        }
        stream_set_blocking($stream, true);
        if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
            $log(["conn" => $id, "event" => "error", "line" => "STARTTLS handshake failed"]);
            $close($id);
            return;
        }
    }
    if (!empty($step["close"])) {
        $close($id);
    }
};

$handle = function (int $id, string $line) use (&$state, $matches, $respond, $log, $send, &$clients): void {
    $script = $state[$id]["script"];
    $pos = $state[$id]["pos"];
    $steps = $script["steps"] ?? [];
    $step = $steps[$pos] ?? null;

    if ($step !== null && $matches($step, $line)) {
        $state[$id]["pos"]++;
        $log(["conn" => $id, "event" => "recv", "line" => $line, "step" => $pos, "auto" => false]);
        $respond($id, $step, $line);
        return;
    }
    foreach ($script["auto"] ?? [] as $auto) {
        if ($matches($auto, $line)) {
            $log(["conn" => $id, "event" => "recv", "line" => $line, "step" => null, "auto" => true]);
            $respond($id, $auto, $line);
            return;
        }
    }
    $expected = $step === null ? "(end of script)" : (string)$step["expect"];
    $log(["conn" => $id, "event" => "unexpected", "line" => $line, "step" => $pos, "expected" => $expected]);
    $tag = explode(" ", $line, 2)[0];
    $send($clients[$id], ($tag === "" ? "*" : $tag) . " BAD [fake server] unexpected command, expected: " . $expected . "\r\n");
};

/**
 * Read exactly $length bytes
 */
$read_bytes = function ($stream, int $length): string {
    $data = "";
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || ($chunk === "" && feof($stream))) {
            break;
        }
        $data .= $chunk;
    }
    return $data;
};

while (microtime(true) < $end) {
    if ($clients === [] && $next_id > count($connections) && $next_id > 1) {
        // every scripted connection has been played and closed
        break;
    }
    $read = array_merge([$server], array_values($clients));
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 50000) === false) {
        break;
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);
            if (!$client) {
                continue;
            }
            $id = $next_id++;
            $conn = $connections[$id - 1] ?? null;
            if ($conn === null) {
                $log(["conn" => $id, "event" => "unexpected", "line" => "(connection)", "step" => null, "expected" => "(no further connection)"]);
                fclose($client);
                continue;
            }
            $clients[$id] = $client;
            $state[$id] = ["script" => $conn, "pos" => 0];
            $log(["conn" => $id, "event" => "accepted"]);
            if (!empty($conn["tls"])) {
                foreach ($tls_context($conn) as $key => $value) {
                    stream_context_set_option($client, "ssl", $key, $value);
                }
                stream_set_blocking($client, true);
                if (!@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    $log(["conn" => $id, "event" => "error", "line" => "TLS handshake failed"]);
                    $close($id);
                    continue;
                }
            }
            $greeting = array_key_exists("greeting", $conn) ? $conn["greeting"] : "* OK [CAPABILITY IMAP4rev1 SASL-IR LOGIN-REFERRALS ID ENABLE IDLE LITERAL+ AUTH=PLAIN] Dovecot ready.";
            if ($greeting !== null) {
                $send($client, $greeting . "\r\n");
            }
            if (!empty($conn["close_after_greeting"])) {
                $close($id);
            }
            continue;
        }
        $id = array_search($stream, $clients, true);
        if ($id === false) {
            continue;
        }
        $line = fgets($stream);
        if ($line === false || $line === "") {
            $close($id);
            continue;
        }
        $line = substr($line, -2) === "\r\n" ? substr($line, 0, -2) : rtrim($line, "\n");

        // complete commands with literals: "TAG1 APPEND "INBOX" {42}" + "+ OK" + 42 bytes + rest of the line
        while (preg_match('/\{(\d+)(\+?)\}$/', $line, $m)) {
            $step = $state[$id]["script"]["steps"][$state[$id]["pos"]] ?? null;
            if ($step !== null && !empty($step["reject_literal"]) && $matches($step, $line)) {
                break;
            }
            if ($m[2] !== "+") {
                $send($stream, "+ OK\r\n");
            }
            $literal = $read_bytes($stream, (int)$m[1]);
            $rest = fgets($stream);
            $rest = $rest === false ? "" : (substr($rest, -2) === "\r\n" ? substr($rest, 0, -2) : rtrim($rest, "\n"));
            $line .= "\r\n" . $literal . $rest;
        }

        $handle($id, $line);
    }
}

foreach (array_keys($clients) as $id) {
    $close($id);
}
fclose($server);
