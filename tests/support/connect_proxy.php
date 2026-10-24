<?php
/*
* File: connect_proxy.php
* Category: Test support
*
* Description:
*  Minimal HTTP CONNECT proxy for the proxy tests (ProtocolTest). It runs as a child process, prints the
*  listening port on the first line of STDOUT, answers every CONNECT request with the given status line
*  and - for a 2xx status - relays the bytes between the client and the requested target.
*
*  Usage: php connect_proxy.php <status line, e.g. "HTTP/1.1 200 Connection established"> <log_file> [lifetime]
*
*  Log (one JSON object per line): {"event": "request", "head": "<request line and headers>"}
*/

$status = $argv[1];
$log_file = $argv[2];
$lifetime = (float)($argv[3] ?? 10);

$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ":") + 1) . "\n";
flush();

$end = microtime(true) + $lifetime;

while (microtime(true) < $end) {
    $client = @stream_socket_accept($server, 0.2);
    if (!$client) {
        continue;
    }
    stream_set_timeout($client, 5);
    $head = "";
    while (($line = fgets($client)) !== false) {
        $head .= $line;
        if (rtrim($line, "\r\n") === "") {
            break;
        }
    }
    file_put_contents($log_file, json_encode(["event" => "request", "head" => $head], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    fwrite($client, $status . "\r\nVia: 1.1 fake-proxy\r\n\r\n");
    if (!preg_match('/^HTTP\/\S+ 2\d\d/', $status) || !preg_match('/^CONNECT (\S+) HTTP/', $head, $m)) {
        fclose($client);
        continue;
    }
    $target = @stream_socket_client("tcp://" . $m[1], $errno, $errstr, 5);
    if (!$target) {
        fclose($client);
        continue;
    }

    // relay until one side closes
    $open = true;
    while ($open && microtime(true) < $end) {
        $read = [$client, $target];
        $write = null;
        $except = null;
        if (@stream_select($read, $write, $except, 0, 100000) === false) {
            break;
        }
        foreach ($read as $stream) {
            $data = fread($stream, 8192);
            if ($data === false || ($data === "" && feof($stream))) {
                $open = false;
                break;
            }
            fwrite($stream === $client ? $target : $client, $data);
        }
    }
    fclose($client);
    fclose($target);
}
fclose($server);
