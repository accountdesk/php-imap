<?php
/*
* File: idle_server.php
* Category: Test support
*
* Description:
*  Minimal fake IMAP server used by IdleTest. It mimics how rfc2177 compliant servers
*  (e.g. Dovecot) react to a command other than DONE during IDLE: the IDLE command is
*  terminated with "BAD Expected DONE". Every received command is logged as
*  "<connection>\t<idling 0|1>\t<line>" to the log file given as first argument.
*
*  Usage: php idle_server.php <log_file> <lifetime_seconds> [scenario]
*  Prints the listening port on the first line of STDOUT.
*
*  Scenarios (a single message with UID 1 arrives - how it is reported differs):
*   - "exists-on-done":  the message arrives while the client ends its first IDLE; like servers that
*                        only report changes when a command completes, the EXISTS is sent between DONE
*                        and the tagged OK. Like GreenMail, every later DONE repeats the current size
*                        ("* 1 EXISTS") - that is not a new message.
*   - "lost-connection": the message arrives while the IDLE connection is gone - the server closes the
*                        first IDLE connection after 0.5 s and the message is only visible to the next
*                        SELECT (no EXISTS is ever sent for it).
*/

$log_file = $argv[1];
$lifetime = (float)($argv[2] ?? 5);
$scenario = (string)($argv[3] ?? "");

// The mailbox: a list of UIDs (message number = position + 1)
$mailbox = [];
$uidnext = 1;
$deliver = function () use (&$mailbox, &$uidnext): int {
    $mailbox[] = $uidnext++;
    return count($mailbox);
};
$raw_message = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: idle test\r\n"
    . "Date: Thu, 22 Oct 2026 10:00:00 +0000\r\nMessage-ID: <idle-test@example.com>\r\n\r\nHello\r\n";
$dropped = false;

$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ":") + 1) . "\n";
flush();

$clients = [];
$state = [];
$next_id = 1;
$end = microtime(true) + $lifetime;

$send = function ($stream, string $data): void {
    @fwrite($stream, $data . "\r\n");
};

while (microtime(true) < $end) {
    $read = array_merge([$server], array_values($clients));
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 100000) === false) {
        break;
    }
    if ($scenario === "lost-connection" && !$dropped) {
        foreach ($state as $id => $st) {
            if ($st["idling"] && microtime(true) - $st["idle_since"] > 0.5) {
                // The connection goes away, the message arrives before the client is back
                $deliver();
                fclose($clients[$id]);
                unset($clients[$id], $state[$id]);
                $dropped = true;
                file_put_contents($log_file, $id . "\t0\t(dropped)\n", FILE_APPEND);
                continue 2;
            }
        }
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client) {
                $id = $next_id++;
                $clients[$id] = $client;
                $state[$id] = ["idling" => false, "idle_tag" => null, "idle_since" => 0.0];
                $send($client, "* OK [CAPABILITY IMAP4rev1 IDLE] fake server ready");
            }
            continue;
        }
        $id = array_search($stream, $clients, true);
        $line = fgets($stream);
        if ($line === false || $line === "") {
            fclose($stream);
            unset($clients[$id], $state[$id]);
            continue;
        }
        $line = rtrim($line, "\r\n");
        file_put_contents($log_file, $id . "\t" . ($state[$id]["idling"] ? 1 : 0) . "\t" . $line . "\n", FILE_APPEND);

        if ($state[$id]["idling"]) {
            $tag = $state[$id]["idle_tag"];
            $state[$id]["idling"] = false;
            if (strtoupper($line) === "DONE") {
                if ($scenario === "exists-on-done") {
                    $send($stream, "* " . ($mailbox === [] ? $deliver() : count($mailbox)) . " EXISTS");
                }
                $send($stream, "$tag OK Idle completed.");
            } else {
                // Same reaction as Dovecot: the IDLE command ends with BAD
                $send($stream, "$tag BAD Expected DONE.");
            }
            continue;
        }

        $parts = explode(" ", $line, 3);
        $tag = $parts[0];
        $command = strtoupper($parts[1] ?? "");
        $by_uid = false;
        if ($command === "UID") {
            $by_uid = true;
            $parts = explode(" ", $parts[2] ?? "", 2);
            $command = strtoupper($parts[0]);
            $parts = [$tag, $command, $parts[1] ?? ""];
        }
        switch ($command) {
            case "SEARCH":
                // only "UID n:*" and "ALL" are needed
                $from = preg_match('/UID (\d+):\*/i', $parts[2], $m) ? (int)$m[1] : 1;
                $hits = [];
                foreach ($mailbox as $msgn => $uid) {
                    if ($uid >= $from) {
                        $hits[] = $by_uid ? $uid : $msgn + 1;
                    }
                }
                if ($hits === [] && $mailbox !== [] && str_contains($parts[2], ":*")) {
                    // "n:*" always matches the highest message
                    $hits[] = $by_uid ? end($mailbox) : count($mailbox);
                }
                $send($stream, "* SEARCH" . ($hits === [] ? "" : " " . implode(" ", $hits)));
                $send($stream, "$tag OK Search completed.");
                break;
            case "FETCH":
                [$set, $items] = explode(" ", $parts[2], 2) + [1 => ""];
                $first = (int)explode(":", $set)[0];
                foreach ($mailbox as $index => $uid) {
                    $msgn = $index + 1;
                    if (($by_uid ? $uid : $msgn) !== $first) {
                        continue;
                    }
                    $fields = [];
                    foreach (preg_split('/\s+/', trim($items, "() ")) as $item) {
                        $name = str_replace(".PEEK", "", strtoupper($item));
                        if ($name === "UID") {
                            continue;
                        }
                        $value = match (true) {
                            $name === "FLAGS" => "()",
                            $name === "RFC822.SIZE" => (string)strlen($raw_message),
                            $name === "INTERNALDATE" => '"22-Oct-2026 10:00:00 +0000"',
                            str_contains($name, "HEADER") => $raw_message,
                            default => substr($raw_message, strpos($raw_message, "\r\n\r\n") + 4),
                        };
                        $fields[] = $name . " " . (in_array($name, ["FLAGS", "RFC822.SIZE", "INTERNALDATE"], true) ? $value : "{" . strlen($value) . "}\r\n" . $value);
                    }
                    $fields[] = "UID " . $uid;
                    @fwrite($stream, "* $msgn FETCH (" . implode(" ", $fields) . ")\r\n");
                }
                $send($stream, "$tag OK Fetch completed.");
                break;
            case "CAPABILITY":
                $send($stream, "* CAPABILITY IMAP4rev1 IDLE");
                $send($stream, "$tag OK Capability completed.");
                break;
            case "SELECT":
            case "EXAMINE":
                $send($stream, "* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)");
                $send($stream, "* " . count($mailbox) . " EXISTS");
                $send($stream, "* 0 RECENT");
                $send($stream, "* OK [UIDVALIDITY 1] UIDs valid");
                $send($stream, "* OK [UIDNEXT $uidnext] Predicted next UID");
                $send($stream, "$tag OK [READ-WRITE] Select completed.");
                break;
            case "IDLE":
                $state[$id]["idling"] = true;
                $state[$id]["idle_tag"] = $tag;
                $state[$id]["idle_since"] = microtime(true);
                $send($stream, "+ idling");
                break;
            case "LOGOUT":
                $send($stream, "* BYE Logging out");
                $send($stream, "$tag OK Logout completed.");
                fclose($stream);
                unset($clients[$id], $state[$id]);
                break;
            default:
                $send($stream, "$tag OK $command completed.");
        }
    }
}

foreach ($clients as $client) {
    fclose($client);
}
fclose($server);
