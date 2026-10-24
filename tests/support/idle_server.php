<?php
/*
* File: idle_server.php
* Category: Test support
*
* Description:
*  Scriptable fake IMAP server used by IdleTest. It behaves like a real server towards every session
*  that selected the mailbox: changes (new messages, expunges) are queued per session and reported
*  while the session is in IDLE ("push") or with the completion of its next NOOP / IDLE / DONE.
*  Like rfc2177 compliant servers (e.g. Dovecot) it answers any command other than DONE during IDLE
*  with "BAD Expected DONE" and terminates the IDLE.
*  Every received command is logged as "<connection>\t<idling 0|1>\t<line>" to the log file; server
*  side actions are logged as "<connection>\t0\t(<action>)".
*
*  Usage: php idle_server.php <log_file> <lifetime_seconds> [scenario]
*  Prints the listening port on the first line of STDOUT.
*
*  [scenario] is either the name of a predefined scenario (see $presets below) or a JSON object:
*   initial                int     messages in the mailbox at start (UIDs 1..n)                   default 0
*   capability             string  CAPABILITY list                                    default "IMAP4rev1 IDLE"
*   push                   bool    report changes immediately while a session is in IDLE           default true
*                                  (false: like Cyrus without idled - only on command completion)
*   before_continuation    bool    report queued changes before "+ idling" instead of after       default false
*   repeat_exists_on_done  bool    send the current size with every completed DONE (GreenMail)    default false
*   still_here             float   send "* OK Still here" every n seconds during IDLE (Dovecot)   default 0 = off
*   idle_reply             string  answer IDLE with "<tag> <idle_reply>" instead of "+ idling"    default ""
*   uidnext_in_select      bool    report UIDNEXT in the SELECT response (Courier does not -       default true
*                                  only STATUS reports it)
*   events                 list    actions, each {"at": seconds after the first IDLE} or {"on_idle": n}
*                                  (when the n-th IDLE command arrives) or {"on_done": n} (while the n-th
*                                  DONE is answered), plus "do" and its arguments:
*      arrive    count=1, recent=false   new messages (subject "message <uid>")
*      expunge   msgn=1                  remove a message
*      send      line                    raw untagged line to the sessions in IDLE
*      drop                              close the sessions in IDLE (FIN)
*      bye       text="Server shutting down"   "* BYE" and close the sessions in IDLE
*      freeze                            sessions in IDLE stop answering, the socket stays open
*                                        (like a NAT gateway that silently dropped the connection)
*      uidvalidity  value                mailbox recreated: new UIDVALIDITY, UIDs renumbered from 1
*      flags     msgn=1, flags="\\Seen"  "* n FETCH (FLAGS (...))" for the sessions that selected the mailbox
*      outage    seconds=2               server restart: close all sessions, refuse connections for n
*                                        seconds, then listen on the same port again
*     Actions that target sessions in IDLE (send, drop, bye, freeze) wait until a session is in IDLE.
*/

$log_file = $argv[1];
$lifetime = (float)($argv[2] ?? 5);
$scenario = (string)($argv[3] ?? "");

$presets = [
    // The message arrives while the client ends its first IDLE; like servers that only report changes when a
    // command completes, the EXISTS is sent between DONE and the tagged OK. Like GreenMail, every later DONE
    // repeats the current size - that is not a new message.
    "exists-on-done"  => ["push" => false, "repeat_exists_on_done" => true, "events" => [["on_done" => 1, "do" => "arrive"]]],
    // The message arrives while the IDLE connection is gone - the server closes the first IDLE connection
    // after 0.5 s and the message is only visible to the next SELECT (no EXISTS is ever sent for it).
    "lost-connection" => ["events" => [["at" => 0.5, "do" => "arrive", "silent" => true], ["at" => 0.5, "do" => "drop"]]],
];
$config = $presets[$scenario] ?? ($scenario !== "" ? json_decode($scenario, true) : []);
if (!is_array($config)) {
    fwrite(STDERR, "invalid scenario: $scenario\n");
    exit(1);
}
$config += [
    "initial" => 0, "capability" => "IMAP4rev1 IDLE", "push" => true, "before_continuation" => false,
    "repeat_exists_on_done" => false, "still_here" => 0, "idle_reply" => "", "uidnext_in_select" => true, "events" => [],
];
$events = $config["events"];

// The mailbox: a list of UIDs (message number = position + 1)
$mailbox = [];
$uidnext = 1;
$uidvalidity = 1;
for ($i = 0; $i < (int)$config["initial"]; $i++) {
    $mailbox[] = $uidnext++;
}
$raw_message = function (int $uid) use (&$uidvalidity): string {
    return "From: sender@example.com\r\nTo: user@example.com\r\nSubject: message $uid\r\n"
        . "Date: Thu, 22 Oct 2026 10:00:00 +0000\r\nMessage-ID: <$uidvalidity.$uid@example.com>\r\n\r\nHello $uid\r\n";
};

// TCP_NODELAY: every response line is a separate write - with Nagle + delayed ACK each roundtrip would take ~40 ms
$context = stream_context_create(["socket" => ["tcp_nodelay" => true]]);
$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
$outage_until = 0.0;
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = substr($name, strrpos($name, ":") + 1);
echo $port . "\n";
flush();

$clients = [];
$state = [];
$next_id = 1;
$end = microtime(true) + $lifetime;
$first_idle = null;
$idle_count = 0;
$done_count = 0;

$log = function (int $id, string $line, bool $idling = false) use ($log_file): void {
    file_put_contents($log_file, $id . "\t" . ($idling ? 1 : 0) . "\t" . $line . "\n", FILE_APPEND);
};
$send = function ($stream, string $data): void {
    @fwrite($stream, $data . "\r\n");
};
$close = function (int $id) use (&$clients, &$state): void {
    if (isset($clients[$id])) {
        @fclose($clients[$id]);
    }
    unset($clients[$id], $state[$id]);
};
// Send the changes queued for a session
$flush = function (int $id) use (&$clients, &$state, $send): void {
    foreach ($state[$id]["queue"] as $line) {
        $send($clients[$id], $line);
    }
    $state[$id]["queue"] = [];
};
// Queue an untagged line for every session that selected the mailbox and push it to sessions in IDLE
$notify = function (string $line) use (&$state, $config, $flush): void {
    foreach ($state as $id => $st) {
        if (!$st["selected"]) {
            continue;
        }
        $state[$id]["queue"][] = $line;
        if ($st["idling"] && $config["push"]) {
            $flush($id);
        }
    }
};
$idling_sessions = function () use (&$state): array {
    return array_keys(array_filter($state, fn($st) => $st["idling"] && !$st["frozen"]));
};

// Execute an event; returns false if it has to wait for a session in IDLE
$run = function (array $event) use (&$mailbox, &$uidnext, &$uidvalidity, &$state, $notify, $idling_sessions, $send, &$clients, $close, $log, &$server, &$outage_until): bool {
    $do = $event["do"];
    if (in_array($do, ["send", "drop", "bye", "freeze"], true)) {
        $targets = $idling_sessions();
        if ($targets === []) {
            return false;
        }
        foreach ($targets as $id) {
            $log($id, "($do)");
            if ($do === "send") {
                $send($clients[$id], $event["line"]);
            } elseif ($do === "freeze") {
                $state[$id]["frozen"] = true;
            } else {
                if ($do === "bye") {
                    $send($clients[$id], "* BYE " . ($event["text"] ?? "Server shutting down"));
                }
                $close($id);
            }
        }
        return true;
    }
    $log(0, "($do)");
    switch ($do) {
        case "arrive":
            for ($i = 0; $i < (int)($event["count"] ?? 1); $i++) {
                $mailbox[] = $uidnext++;
            }
            if (!($event["silent"] ?? false)) {
                $notify("* " . count($mailbox) . " EXISTS");
                if ($event["recent"] ?? false) {
                    $notify("* " . (int)($event["count"] ?? 1) . " RECENT");
                }
            }
            break;
        case "expunge":
            $msgn = (int)($event["msgn"] ?? 1);
            array_splice($mailbox, $msgn - 1, 1);
            $notify("* $msgn EXPUNGE");
            break;
        case "uidvalidity":
            $uidvalidity = (int)$event["value"];
            $mailbox = $mailbox === [] ? [] : range(1, count($mailbox));
            $uidnext = count($mailbox) + 1;
            break;
        case "outage":
            foreach (array_keys($clients) as $id) {
                $close($id);
            }
            fclose($server);
            $server = null;
            $outage_until = microtime(true) + (float)($event["seconds"] ?? 2);
            break;
        case "flags":
            $notify("* " . (int)($event["msgn"] ?? 1) . " FETCH (FLAGS (" . ($event["flags"] ?? "\\Seen") . "))");
            break;
    }
    return true;
};
// Run the events of a trigger ("on_idle" / "on_done" with the given count)
$trigger = function (string $type, int $count) use (&$events, $run): void {
    foreach ($events as $index => $event) {
        if (($event[$type] ?? null) === $count) {
            $run($event);
            unset($events[$index]);
        }
    }
};

// Message numbers or UIDs of a sequence set such as "1", "2:4", "3:*" or "1,5"
$resolve_set = function (string $set, bool $by_uid) use (&$mailbox): array {
    $ids = [];
    foreach ($mailbox as $index => $uid) {
        $ids[] = $by_uid ? $uid : $index + 1;
    }
    $max = $ids === [] ? 0 : max($ids);
    $result = [];
    foreach (explode(",", $set) as $range) {
        $bounds = explode(":", $range);
        $from = $bounds[0] === "*" ? $max : (int)$bounds[0];
        $to = !isset($bounds[1]) ? $from : ($bounds[1] === "*" ? $max : (int)$bounds[1]);
        [$from, $to] = [min($from, $to), max($from, $to)];
        foreach ($ids as $value) {
            if ($value >= $from && $value <= $to) {
                $result[] = $value;
            }
        }
    }
    return array_values(array_unique($result));
};

while (microtime(true) < $end) {
    if ($server === null && microtime(true) >= $outage_until) {
        $server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $log(0, "(back)");
    }
    if ($server === null) {
        usleep(50000);
        $read = [];
    } else {
        $read = array_merge([$server], array_values($clients));
    }
    $write = null;
    $except = null;
    if ($read !== [] && @stream_select($read, $write, $except, 0, 50000) === false) {
        break;
    }

    if ($first_idle !== null) {
        $elapsed = microtime(true) - $first_idle;
        foreach ($events as $index => $event) {
            if (isset($event["at"]) && $elapsed >= (float)$event["at"] && $run($event)) {
                unset($events[$index]);
            }
        }
        if ((float)$config["still_here"] > 0) {
            foreach ($idling_sessions() as $id) {
                if (microtime(true) - $state[$id]["last_still_here"] >= (float)$config["still_here"]) {
                    $send($clients[$id], "* OK Still here");
                    $state[$id]["last_still_here"] = microtime(true);
                }
            }
        }
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client) {
                $id = $next_id++;
                $clients[$id] = $client;
                $state[$id] = ["idling" => false, "idle_tag" => null, "selected" => false, "frozen" => false, "queue" => [], "last_still_here" => 0.0];
                $send($client, "* OK [CAPABILITY " . $config["capability"] . "] fake server ready");
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
        $line = rtrim($line, "\r\n");
        $log($id, $line, $state[$id]["idling"]);
        if ($state[$id]["frozen"]) {
            continue;
        }

        if ($state[$id]["idling"]) {
            $tag = $state[$id]["idle_tag"];
            $state[$id]["idling"] = false;
            if (strtoupper($line) === "DONE") {
                $trigger("on_done", ++$done_count);
                if (!isset($clients[$id])) {
                    continue;
                }
                $repeat = $config["repeat_exists_on_done"] && $state[$id]["queue"] === [];
                $flush($id);
                if ($repeat) {
                    $send($stream, "* " . count($mailbox) . " EXISTS");
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
                // "UID n:*", "ALL" and plain sets are enough for idle()
                $criteria = trim($parts[2]);
                if (preg_match('/^UID (\S+)$/i', $criteria, $m)) {
                    $hits = $resolve_set($m[1], true);
                    if (!$by_uid) {
                        $hits = array_map(fn($uid) => array_search($uid, $mailbox, true) + 1, $hits);
                    }
                } else {
                    $hits = $by_uid ? $mailbox : ($mailbox === [] ? [] : range(1, count($mailbox)));
                }
                $send($stream, "* SEARCH" . ($hits === [] ? "" : " " . implode(" ", $hits)));
                $send($stream, "$tag OK Search completed.");
                break;
            case "FETCH":
                [$set, $items] = explode(" ", $parts[2], 2) + [1 => ""];
                foreach ($resolve_set($set, $by_uid) as $value) {
                    $msgn = $by_uid ? array_search($value, $mailbox, true) + 1 : $value;
                    $uid = $mailbox[$msgn - 1];
                    $raw = $raw_message($uid);
                    $fields = [];
                    foreach (preg_split('/\s+/', trim($items, "() ")) as $item) {
                        $name = str_replace(".PEEK", "", strtoupper($item));
                        if ($name === "UID" || $name === "") {
                            continue;
                        }
                        $content = match (true) {
                            $name === "FLAGS" => "()",
                            $name === "RFC822.SIZE" => (string)strlen($raw),
                            $name === "INTERNALDATE" => '"22-Oct-2026 10:00:00 +0000"',
                            str_contains($name, "HEADER") => substr($raw, 0, strpos($raw, "\r\n\r\n") + 4),
                            default => substr($raw, strpos($raw, "\r\n\r\n") + 4),
                        };
                        $fields[] = $name . " " . (in_array($name, ["FLAGS", "RFC822.SIZE", "INTERNALDATE"], true) ? $content : "{" . strlen($content) . "}\r\n" . $content);
                    }
                    $fields[] = "UID " . $uid;
                    @fwrite($stream, "* $msgn FETCH (" . implode(" ", $fields) . ")\r\n");
                }
                $send($stream, "$tag OK Fetch completed.");
                break;
            case "CAPABILITY":
                $send($stream, "* CAPABILITY " . $config["capability"]);
                $send($stream, "$tag OK Capability completed.");
                break;
            case "SELECT":
            case "EXAMINE":
                $state[$id]["selected"] = true;
                $state[$id]["queue"] = [];
                $send($stream, "* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)");
                $send($stream, "* " . count($mailbox) . " EXISTS");
                $send($stream, "* 0 RECENT");
                $send($stream, "* OK [UIDVALIDITY $uidvalidity] UIDs valid");
                if ($config["uidnext_in_select"]) {
                    $send($stream, "* OK [UIDNEXT $uidnext] Predicted next UID");
                }
                $send($stream, "$tag OK [READ-WRITE] Select completed.");
                break;
            case "STATUS":
                $mailbox_name = preg_match('/^("[^"]*"|\S+)/', $parts[2], $m) ? $m[1] : "INBOX";
                $send($stream, "* STATUS $mailbox_name (UIDNEXT $uidnext UIDVALIDITY $uidvalidity MESSAGES " . count($mailbox) . ")");
                $send($stream, "$tag OK Status completed.");
                break;
            case "NOOP":
            case "CHECK":
                $flush($id);
                $send($stream, "$tag OK $command completed.");
                break;
            case "IDLE":
                if ($config["idle_reply"] !== "") {
                    $send($stream, "$tag " . $config["idle_reply"]);
                    break;
                }
                $first_idle ??= microtime(true);
                $trigger("on_idle", ++$idle_count);
                if (!isset($clients[$id])) {
                    break;
                }
                if ($config["before_continuation"]) {
                    $flush($id);
                }
                $state[$id]["idling"] = true;
                $state[$id]["idle_tag"] = $tag;
                $state[$id]["last_still_here"] = microtime(true);
                $send($stream, "+ idling");
                if ($config["push"]) {
                    $flush($id);
                }
                break;
            case "LOGOUT":
                $send($stream, "* BYE Logging out");
                $send($stream, "$tag OK Logout completed.");
                $close($id);
                break;
            default:
                $send($stream, "$tag OK $command completed.");
        }
    }
}

foreach (array_keys($clients) as $id) {
    $close($id);
}
if ($server !== null) {
    fclose($server);
}
