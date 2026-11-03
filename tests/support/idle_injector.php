<?php
/*
* File: idle_injector.php
* Category: Test support
*
* Description:
*  Companion process for IdleLiveTest: while the test process blocks in Folder::idle(), this process
*  changes the mailbox on its own connection and disturbs the network through Toxiproxy - following a
*  timeline. Every executed action is written as one JSON line to the log file.
*
*  Usage: php idle_injector.php <job.json> <log_file>
*  job.json: {"account": {...ClientManager account...}, "folder": "INBOX/x", "toxiproxy": "http://...",
*             "proxy": "dovecot23", "ready_file": "/tmp/x", "actions": [{"at": 2.0, "do": "append", "subject": "a"}, ...]}
*  The injector logs in first, then writes "ready" to ready_file and starts the timeline.
*  Actions ("at" = seconds after the timeline started):
*   append        subject                 APPEND a message
*   delete        subject                 flag \Deleted + EXPUNGE the message with this subject
*   flag          subject, flag="Seen"    set a flag
*   proxy-down / proxy-up                 disable / enable the Toxiproxy proxy (closes all connections)
*   blackhole / blackhole-off             timeout toxic (timeout 0, both directions): data is dropped,
*                                         the connection stays open - like a NAT gateway that forgot it
*/

require __DIR__ . "/../../vendor/autoload.php";

use Tests\support\Toxiproxy;
use Webklex\PHPIMAP\ClientManager;

$job = json_decode((string)file_get_contents($argv[1]), true);
$log_file = $argv[2];

$log = function (array $entry) use ($log_file): void {
    file_put_contents($log_file, json_encode($entry) . "\n", FILE_APPEND);
};

$manager = new ClientManager(["options" => ["debug" => false], "accounts" => ["injector" => $job["account"]]]);
$client = $manager->account("injector");
$toxiproxy = isset($job["toxiproxy"]) ? new Toxiproxy($job["toxiproxy"]) : null;
$toxics = [];

$folder = function () use ($client, $job) {
    $client->checkConnection();
    return $client->getFolderByPath($job["folder"]);
};
$find = function (string $subject) use ($folder) {
    foreach ($folder()->query()->all()->get() as $message) {
        if ((string)$message->getSubject() === $subject) {
            return $message;
        }
    }
    throw new RuntimeException("message \"$subject\" not found");
};

// connect before the timeline starts and tell the test (which starts idle() only then): on slow machines
// (CI runners) start-up and login would otherwise shift every action towards the end of the idle runtime
try {
    $folder();
} catch (Throwable $e) {
    $log(["at" => 0, "do" => "connect", "subject" => null, "ok" => false, "error" => $e->getMessage()]);
}
if (!empty($job["ready_file"])) {
    file_put_contents($job["ready_file"], "ready");
}

$start = microtime(true);
$actions = $job["actions"];
usort($actions, fn($a, $b) => $a["at"] <=> $b["at"]);

foreach ($actions as $action) {
    $wait = $start + (float)$action["at"] - microtime(true);
    if ($wait > 0) {
        usleep((int)($wait * 1000000));
    }
    try {
        switch ($action["do"]) {
            case "append":
                $raw = "From: injector@example.com\r\nTo: idle@example.com\r\nSubject: " . $action["subject"] . "\r\n"
                    . "Date: " . date("r") . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@php-imap.test>\r\n"
                    . "Content-Type: text/plain; charset=utf-8\r\n\r\nidle test " . $action["subject"] . "\r\n";
                $folder()->appendMessage($raw);
                break;
            case "delete":
                $find($action["subject"])->delete(true);
                break;
            case "flag":
                $find($action["subject"])->setFlag($action["flag"] ?? "Seen");
                break;
            case "proxy-down":
                $toxiproxy->disable($job["proxy"]);
                break;
            case "proxy-up":
                $toxiproxy->enable($job["proxy"]);
                break;
            case "blackhole":
                foreach (["downstream", "upstream"] as $stream) {
                    $toxics[] = $toxiproxy->addToxic($job["proxy"], "timeout", ["timeout" => 0], $stream, "blackhole-$stream");
                }
                break;
            case "blackhole-off":
                foreach ($toxics as $name) {
                    $toxiproxy->removeToxic($job["proxy"], $name);
                }
                $toxics = [];
                break;
            default:
                throw new RuntimeException("unknown action " . $action["do"]);
        }
        $log(["at" => round(microtime(true) - $start, 2), "do" => $action["do"], "subject" => $action["subject"] ?? null, "ok" => true]);
    } catch (Throwable $e) {
        $log(["at" => round(microtime(true) - $start, 2), "do" => $action["do"], "subject" => $action["subject"] ?? null, "ok" => false, "error" => $e->getMessage()]);
    }
}
