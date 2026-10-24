<?php
/*
* File:     Folder.php
* Category: -
* Author:   M. Goldenbaum
* Created:  19.01.17 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Carbon\Carbon;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\EventNotFoundException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\NotSupportedCapabilityException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Traits\HasEvents;

/**
 * Class Folder
 *
 * @package Webklex\PHPIMAP
 */
class Folder {
    use HasEvents;

    /**
     * Client instance
     *
     * @var Client
     */
    protected Client $client;

    /**
     * Folder full path
     *
     * @var string
     */
    public string $path;

    /**
     * Folder name
     *
     * @var string
     */
    public string $name;

    /**
     * Folder full name
     *
     * @var string
     */
    public string $full_name;

    /**
     * Children folders
     *
     * @var FolderCollection
     */
    public FolderCollection $children;

    /**
     * Delimiter for folder
     *
     * @var string
     */
    public string $delimiter;

    /**
     * Indicates if folder can't contain any "children".
     * CreateFolder won't work on this folder.
     *
     * @var boolean
     */
    public bool $no_inferiors;

    /**
     * Indicates if folder is only container, not a mailbox - you can't open it.
     *
     * @var boolean
     */
    public bool $no_select;

    /**
     * Indicates if folder is marked. This means that it may contain new messages since the last time it was checked.
     * Not provided by all IMAP servers.
     *
     * @var boolean
     */
    public bool $marked;

    /**
     * Indicates if folder contains any "children".
     * Not provided by all IMAP servers.
     *
     * @var boolean
     */
    public bool $has_children;

    /**
     * Indicates if folder refers to others.
     * Not provided by all IMAP servers.
     *
     * @var boolean
     */
    public bool $referral;

    /** @var array */
    public array $status;

    /**
     * Folder constructor.
     * @param Client $client
     * @param string $folder_name
     * @param string $delimiter
     * @param string[] $attributes
     */
    public function __construct(Client $client, string $folder_name, string $delimiter, array $attributes) {
        $this->client = $client;

        $this->events["message"] = $client->getDefaultEvents("message");
        $this->events["folder"] = $client->getDefaultEvents("folder");

        $this->setDelimiter($delimiter);
        $this->path = $folder_name;
        $this->full_name = $this->decodeName($folder_name);
        $this->name = $this->getSimpleName($this->delimiter, $this->full_name);
        $this->children = new FolderCollection();
        $this->has_children = false;

        $this->parseAttributes($attributes);
    }

    /**
     * Get a new search query instance
     * @param string[] $extensions
     *
     * @return WhereQuery
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ResponseException
     */
    public function query(array $extensions = []): WhereQuery {
        $this->getClient()->checkConnection();
        $this->getClient()->openFolder($this->path);
        $extensions = count($extensions) > 0 ? $extensions : $this->getClient()->extensions;

        return new WhereQuery($this->getClient(), $extensions);
    }

    /**
     * Get a new search query instance
     * @param string[] $extensions
     *
     * @return WhereQuery
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ResponseException
     */
    public function search(array $extensions = []): WhereQuery {
        return $this->query($extensions);
    }

    /**
     * Get a new search query instance
     * @param string[] $extensions
     *
     * @return WhereQuery
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ResponseException
     */
    public function messages(array $extensions = []): WhereQuery {
        return $this->query($extensions);
    }

    /**
     * Determine if folder has children.
     *
     * @return bool
     */
    public function hasChildren(): bool {
        return $this->has_children;
    }

    /**
     * Set children.
     * @param FolderCollection $children
     *
     * @return Folder
     */
    public function setChildren(FolderCollection $children): Folder {
        $this->children = $children;

        return $this;
    }

    /**
     * Get children.
     *
     * @return FolderCollection
     */
    public function getChildren(): FolderCollection {
        return $this->children;
    }

    /**
     * Decode name.
     * It converts UTF7-IMAP encoding to UTF-8.
     * @param $name
     *
     * @return string|array|bool|string[]|null
     */
    protected function decodeName($name): string|array|bool|null {
        $parts = [];
        foreach (explode($this->delimiter, $name) as $item) {
            $parts[] = EncodingAliases::convert($item, "UTF7-IMAP");
        }

        return implode($this->delimiter, $parts);
    }

    /**
     * Get simple name (without parent folders).
     * @param $delimiter
     * @param $full_name
     *
     * @return string|bool
     */
    protected function getSimpleName($delimiter, $full_name): string|bool {
        $arr = explode($delimiter, $full_name);
        return end($arr);
    }

    /**
     * Parse attributes and set it to object properties.
     * @param $attributes
     */
    protected function parseAttributes($attributes): void {
        $this->no_inferiors = in_array('\NoInferiors', $attributes, true) || \in_array('\Noinferiors', $attributes, true);
        $this->no_select = in_array('\NoSelect', $attributes, true) || \in_array('\Noselect', $attributes, true);
        $this->marked = in_array('\Marked', $attributes);
        $this->referral = in_array('\Referral', $attributes);
        $this->has_children = in_array('\HasChildren', $attributes);
    }

    /**
     * Move or rename the current folder
     * @param string $new_name new full name (path), UTF-8 unless $utf7 is true
     * @param boolean $expunge
     * @param bool $utf7 true if $new_name is already encoded in modified UTF-7 (RFC 3501 5.1.3)
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function move(string $new_name, bool $expunge = true, bool $utf7 = false): array {
        $this->client->checkConnection();
        // the server knows the folder by its (UTF-7) path, full_name is the decoded name
        $new_path = $utf7 ? $new_name : EncodingAliases::convert($new_name, "utf-8", "UTF7-IMAP");
        $status = $this->client->getConnection()->renameFolder($this->path, $new_path)->validatedData();
        // EXPUNGE needs a selected mailbox (RFC 3501 6.4.3), without one the server answers BAD
        if ($expunge && $this->client->getActiveFolder() !== null) $this->client->expunge();

        $folder = $this->client->getFolderByPath($new_path, true);
        $this->dispatch("folder", "moved", $this, $folder);

        return $status;
    }

    /**
     * Get a message overview
     * @param string|null $sequence uid sequence
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws InvalidMessageDateException
     * @throws MessageNotFoundException
     * @throws ResponseException
     */
    public function overview(?string $sequence = null): array {
        $this->client->openFolder($this->path);
        $sequence = $sequence === null ? "1:*" : $sequence;
        $uid = $this->client->getConfig()->get('options.sequence', IMAP::ST_MSGN);
        $response = $this->client->getConnection()->overview($sequence, $uid);
        return $response->validatedData();
    }

    /**
     * Append a string message to the current mailbox
     * @param string $message
     * @param array|null $options
     * @param string|Carbon|null $internal_date
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function appendMessage(string $message, ?array $options = null, Carbon|string|null $internal_date = null): array {
        /**
         * Check if $internal_date is parsed. If it is null it should not be set. Otherwise, the message can't be stored.
         * If this parameter is set, it will set the INTERNALDATE on the appended message. The parameter should be a
         * date string that conforms to the rfc2060 specifications for a date_time value or be a Carbon object.
         */

        if ($internal_date instanceof Carbon) {
            $internal_date = $internal_date->format('d-M-Y H:i:s O');
        }

        return $this->client->getConnection()->appendMessage($this->path, $message, $options, $internal_date)->validatedData();
    }

    /**
     * Rename the current folder
     * @param string $new_name new full name (path), UTF-8 unless $utf7 is true
     * @param boolean $expunge
     * @param bool $utf7 true if $new_name is already encoded in modified UTF-7
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws FolderFetchingException
     * @throws RuntimeException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function rename(string $new_name, bool $expunge = true, bool $utf7 = false): array {
        return $this->move($new_name, $expunge, $utf7);
    }

    /**
     * Delete the current folder
     * @param boolean $expunge
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws EventNotFoundException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function delete(bool $expunge = true): array {
        $status = $this->client->getConnection()->deleteFolder($this->path)->validatedData();
        if ($this->client->getActiveFolder() == $this->path){
            $this->client->setActiveFolder();
        }

        // EXPUNGE needs a selected mailbox (RFC 3501 6.4.3)
        if ($expunge && $this->client->getActiveFolder() !== null) $this->client->expunge();

        $this->dispatch("folder", "deleted", $this);

        return $status;
    }

    /**
     * Subscribe the current folder
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function subscribe(): array {
        $this->client->openFolder($this->path);
        return $this->client->getConnection()->subscribeFolder($this->path)->validatedData();
    }

    /**
     * Unsubscribe the current folder
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function unsubscribe(): array {
        $this->client->openFolder($this->path);
        return $this->client->getConnection()->unsubscribeFolder($this->path)->validatedData();
    }

    /**
     * Idle the current connection
     * @param callable $callback function(Message $message) gets called if a new message is received
     * @param integer $timeout max 1740 seconds - recommended by rfc2177 §3. Should not be lower than the servers "* OK Still here" message interval.
     *                         Keep-alive interval: every $timeout seconds - also if the server sends other untagged
     *                         data in between (the read timeout is shortened to the time left) - the IDLE command
     *                         gets re-issued (DONE + IDLE) as a
     *                         keep-alive (rfc2177 §3 recommends re-issuing at least every 29 minutes; many servers
     *                         and NAT gateways drop idle connections much earlier).
     * @param integer $max_runtime maximum runtime in seconds, 0 = run forever. When the limit is reached, idle()
     *                             terminates the IDLE session gracefully (DONE) and returns runtime statistics.
     *                             Useful for cron/worker setups that want bounded runs instead of an endless loop.
     * @param callable|null $on_keepalive function(string $reason) gets called after every keep-alive ($reason =
     *                             "keepalive") and after every reconnect of the IDLE session ($reason = "reconnect"),
     *                             once the session is back in IDLE. Use it to verify that no message was missed,
     *                             e.g. by comparing UIDNEXT from Folder::status(): some servers silently stop sending
     *                             EXISTS. (EXISTS lines that arrive while DONE/IDLE are exchanged, and messages that
     *                             arrive while the IDLE session is reconnecting, are reported by idle() itself.)
     *                             The callback must not use the IDLE session itself - Folder::status(),
     *                             Folder::query() etc. run on the regular client connection, which is not in IDLE.
     *
     * @return array{messages: int, keepalives: int, reconnects: int, checks: int, runtime: int}
     *         checks = number of $on_keepalive calls
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws NotSupportedCapabilityException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws ResponseException
     */
    public function idle(callable $callback, int $timeout = 300, int $max_runtime = 0, ?callable $on_keepalive = null): array {
        $this->client->setTimeout($timeout);

        if (!in_array("IDLE", $this->client->getConnection()->getCapabilities()->validatedData())) {
            throw new Exceptions\NotSupportedCapabilityException("IMAP server does not support IDLE");
        }

        $idle_client = $this->client->clone();
        $idle_client->connect();

        // Select the folder on the idle session. UIDNEXT is needed to find messages that arrive while the
        // session is down - servers that leave it out of the SELECT response (Courier) are asked via STATUS.
        $select = function () use ($idle_client): array {
            $selected = $idle_client->openFolder($this->path, true);
            if (!isset($selected["uidnext"]) && $idle_client->getConnection() instanceof \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol) {
                try {
                    $status = $idle_client->getConnection()->folderStatus($this->path, ["UIDNEXT", "UIDVALIDITY"])->validatedData();
                    $selected["uidnext"] = $status["uidnext"] ?? null;
                    $selected["uidvalidity"] ??= $status["uidvalidity"] ?? null;
                } catch (Exceptions\RuntimeException|ImapBadRequestException|ImapServerErrorException|ResponseException) {
                    // no UIDNEXT - messages that arrive during a reconnect cannot be detected
                }
            }
            return $selected;
        };
        $selected = $select();

        $sequence = $this->client->getConfig()->get('options.sequence', IMAP::ST_MSGN);

        $stats = ["messages" => 0, "keepalives" => 0, "reconnects" => 0, "checks" => 0, "runtime" => 0];
        $last_action = Carbon::now()->addSeconds($timeout);

        // Lowest UID a new message can have. Messages that arrive while the IDLE session is down (between
        // a connection loss and the reconnect) never produce an EXISTS on the new session - they are only
        // part of its SELECT response. They are found by comparing UIDNEXT before and after the reconnect.
        $uidnext = (int)($selected["uidnext"] ?? 0);
        $uidvalidity = (int)($selected["uidvalidity"] ?? 0);
        // Known mailbox size (-1 = unknown). EXISTS reports the size, not "a new message": servers may repeat
        // it (GreenMail sends the current size again with every completed DONE). Only a larger size is new.
        $exists = (int)($selected["exists"] ?? -1);

        $deliver = function (Message $message) use ($callback, $sequence, &$stats, &$uidnext): void {
            $uid = (int)$message->getUid();
            if ($uidnext > 0 && $uid > 0 && $uid < $uidnext) {
                // already delivered
                return;
            }
            $message->setSequence($sequence);
            $uidnext = max($uidnext, $uid + 1);
            $callback($message);
            $stats["messages"]++;

            $this->dispatch("message", "new", $message);
        };

        $prepare_client = function () use (&$last_action, $timeout): void {
            // Check if the stream is still alive or should be considered stale
            if (!$this->client->isConnected() || $last_action->isBefore(Carbon::now())) {
                // Reset the connection before interacting with it. Otherwise, the resource might be stale which
                // would result in a stuck interaction. If you know of a way of detecting a stale resource, please
                // feel free to improve this logic. I tried a lot but nothing seem to work reliably...
                // Things that didn't work:
                //      - Closing the resource with fclose()
                //      - Verifying the resource with stream_get_meta_data()
                //      - Bool validating the resource stream (e.g.: (bool)$stream)
                //      - Sending a NOOP command
                //      - Sending a null package
                //      - Reading a null package
                //      - Catching the fs warning

                // This polymorphic call is fine - Protocol::idle() will throw an exception beforehand
                $this->client->getConnection()->reset();
                // Establish a new connection
                $this->client->connect();
            }
            $last_action = Carbon::now()->addSeconds($timeout);

            // Always reopen the folder - otherwise the new message number isn't known to the current remote session
            $this->client->openFolder($this->path, true);
        };

        $handle_line = function (string $line) use ($prepare_client, $deliver, &$exists): void {
            if (preg_match('/^\* \d+ EXPUNGE/i', $line) === 1) {
                if ($exists > 0) {
                    $exists--;
                }
                return;
            }
            if (($pos = strpos($line, "EXISTS")) === false) {
                return;
            }
            $msgn = (int)substr($line, 2, $pos - 2);
            $known = $exists;
            $exists = $msgn;
            if ($msgn < 1 || ($known >= 0 && $msgn <= $known)) {
                // no new message - the server repeated the size or reported a shrunk mailbox
                return;
            }
            $prepare_client();
            // Several messages at once (e.g. reported together after DONE): deliver all of them, at most 50
            $first = $known >= 0 ? max($known + 1, $msgn - 49) : $msgn;
            for ($n = $first; $n <= $msgn; $n++) {
                $deliver($this->query()->getMessageByMsgn($n));
            }
        };

        // EXISTS lines the server sent while IDLE was started or ended - see ImapProtocol::idle() and done()
        $handle_idle_untagged = function ($connection) use ($handle_line): void {
            if ($connection instanceof \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol) {
                foreach ($connection->pullIdleUntaggedResponses() as $line) {
                    $handle_line($line);
                }
            }
        };

        // Fetch the protocol connection once, before entering IDLE. Client::getConnection() runs
        // checkConnection(), which verifies the session with a NOOP. Sending a NOOP while the session
        // is in IDLE violates rfc2177 - servers such as Dovecot answer "BAD Expected DONE" and end the
        // IDLE, checkConnection() then silently opens a new session without IDLE and no EXISTS ever
        // arrives. Inside the loop only the protocol connection itself must be used.
        $connection = $idle_client->getConnection();
        $connection->idle();
        $handle_idle_untagged($connection);

        $start = time();
        $last_keepalive = $start;

        while ($max_runtime <= 0 || (time() - $start) < $max_runtime) {
            $line = null;
            $alive = true;
            // Wait at most until the next keep-alive is due. Otherwise untagged data such as Dovecot's
            // "* OK Still here" restarts the read timeout and stretches the interval up to 2 x $timeout.
            $stream = $connection instanceof \Webklex\PHPIMAP\Connection\Protocols\Protocol ? $connection->getStream() : null;
            $wait = max(1, $timeout - (time() - $last_keepalive));
            // ... and at most until $max_runtime is reached - a keep-alive interval longer than the
            // remaining runtime must not keep idle() running past $max_runtime
            $runtime_left = $max_runtime > 0 ? max(1, $max_runtime - (time() - $start)) : $wait;
            $cut_by_runtime = $runtime_left < $wait;
            $wait = min($wait, $runtime_left);
            $shortened = is_resource($stream) && $wait < $timeout;
            if ($shortened) {
                stream_set_timeout($stream, $wait);
            }
            try {
                // This polymorphic call is fine - Protocol::idle() will throw an exception beforehand
                $line = $connection->nextLine(Response::empty());
            } catch (Exceptions\RuntimeException $e) {
                if (!str_contains($e->getMessage(), "empty response") && !str_contains($e->getMessage(), "connection closed")) {
                    throw $e;
                }
                // A plain read timeout leaves the connection alive, EOF means it is gone
                $meta = $connection->meta();
                $alive = ($meta["timed_out"] ?? false) && !($meta["eof"] ?? true);
            } finally {
                // DONE/IDLE and the commands of the callbacks read with the regular timeout
                if ($shortened && is_resource($stream)) {
                    stream_set_timeout($stream, $timeout);
                }
            }

            if ($line !== null) {
                $handle_line($line);
            }

            if ($alive && (($line === null && !$cut_by_runtime) || (time() - $last_keepalive) >= $timeout)) {
                // Re-issue IDLE as a keep-alive (rfc2177 §3) so the server does not drop the session for
                // inactivity - after a read timeout, or when the server kept sending other untagged data.
                // If the roundtrip fails the connection is silently dead - fall through to a reconnect.
                try {
                    $connection->done();
                    $connection->idle();
                } catch (Exceptions\RuntimeException) {
                    // silently dead (e.g. NAT drop without FIN) - reconnect below
                    $alive = false;
                }
                // An EXISTS that arrived during the DONE/IDLE exchange is not lost (the session is back in
                // IDLE - the callback works on the regular client connection)
                $handle_idle_untagged($connection);
                if ($alive) {
                    $stats["keepalives"]++;
                    $last_keepalive = time();
                    if ($on_keepalive !== null) {
                        $on_keepalive("keepalive");
                        $stats["checks"]++;
                    }
                    continue;
                }
            }

            if (!$alive) {
                // EOF - the server or an intermediate gateway closed the connection (e.g. after an
                // inactivity timeout such as the ~30 minutes granted by RFC 2177). Re-establish the
                // idle session instead of busy-looping on the dead stream.
                $connection->reset();
                // The server may be unreachable for a moment (restart, failover, network blip): retry with a
                // growing delay instead of giving up - the state needed to deliver the messages that arrive
                // meanwhile (UIDNEXT) only lives in this loop. Give up after max(timeout, 60) seconds or when
                // $max_runtime is reached.
                $delay = 1;
                $give_up = time() + max($timeout, 60);
                while (true) {
                    try {
                        $idle_client->connect();
                        $selected = $select();
                        break;
                    } catch (ConnectionFailedException|RuntimeException|AuthFailedException $e) {
                        // A rejected login is final - but a login that got no answer (the network is still
                        // dead) surfaces as AuthFailedException caused by a RuntimeException and is retried
                        if ($e instanceof AuthFailedException && !($e->getPrevious() instanceof RuntimeException)) {
                            throw $e;
                        }
                        $runtime_over = $max_runtime > 0 && (time() + $delay - $start) >= $max_runtime;
                        if (time() + $delay > $give_up || $runtime_over) {
                            throw $e;
                        }
                        sleep($delay);
                        $delay = min($delay * 2, 30);
                    }
                }
                // connect() created a new protocol instance - pick it up before entering IDLE again
                $connection = $idle_client->getConnection();

                // Messages that arrived while the session was down: UIDNEXT moved, but no EXISTS will follow
                $new_uidnext = (int)($selected["uidnext"] ?? 0);
                $new_uidvalidity = (int)($selected["uidvalidity"] ?? 0);
                $missed = [];
                if ($uidnext > 0 && $new_uidnext > $uidnext && $new_uidvalidity === $uidvalidity) {
                    $prepare_client();
                    $found = $this->client->getConnection()->search(["UID", $uidnext . ":*"], IMAP::ST_UID)->validatedData();
                    foreach ($found as $uid) {
                        // "n:*" always matches the highest UID, even if it is lower than n
                        if ((int)$uid >= $uidnext && (int)$uid < $new_uidnext) {
                            $missed[] = (int)$uid;
                        }
                    }
                    sort($missed);
                }
                $exists = (int)($selected["exists"] ?? -1);
                if ($new_uidvalidity !== $uidvalidity || $uidnext === 0) {
                    // UIDs of the old session are meaningless now - start over from the current state
                    $uidvalidity = $new_uidvalidity;
                    $uidnext = $new_uidnext;
                }

                $connection->idle();
                $stats["reconnects"]++;
                $last_keepalive = time();

                foreach ($missed as $uid) {
                    if ($uid >= $uidnext) {
                        $deliver($this->query()->getMessageByUid($uid));
                    }
                }
                $handle_idle_untagged($connection);

                if ($on_keepalive !== null) {
                    $on_keepalive("reconnect");
                    $stats["checks"]++;
                }
            }
        }

        // Max runtime reached: terminate the IDLE session gracefully. The connection
        // may already be gone - a failing DONE must not discard the collected stats.
        try {
            $connection->done();
        } catch (Exceptions\RuntimeException) {
            // connection already closed
        }
        // Report what the server sent until the IDLE command completed (some servers report changes only then)
        $handle_idle_untagged($connection);

        $stats["runtime"] = time() - $start;
        return $stats;
    }

    /**
     * Get folder status information from the EXAMINE command
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function status(): array {
        return $this->client->getConnection()->folderStatus($this->path)->validatedData();
    }

    /**
     * Get folder status information from the EXAMINE command
     *
     * @return array
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws ResponseException
     * @throws RuntimeException
     *
     * @deprecated Use Folder::status() instead
     */
    public function getStatus(): array {
        return $this->status();
    }

    /**
     * Load folder status information from the EXAMINE command
     * @return Folder
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function loadStatus(): Folder {
        $this->status = $this->examine();
        return $this;
    }

    /**
     * Examine the current folder
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws AuthFailedException
     * @throws ResponseException
     */
    public function examine(): array {
        return $this->client->getConnection()->examineFolder($this->path)->validatedData();
    }

    /**
     * Select the current folder
     *
     * @return array
     * @throws AuthFailedException
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function select(): array {
        return $this->client->getConnection()->selectFolder($this->path)->validatedData();
    }

    /**
     * Get the current Client instance
     *
     * @return Client
     */
    public function getClient(): Client {
        return $this->client;
    }

    /**
     * Set the delimiter
     * @param $delimiter
     */
    public function setDelimiter($delimiter): void {
        if (in_array($delimiter, [null, '', ' ', false]) === true) {
            $delimiter = $this->client->getConfig()->get('options.delimiter', '/');
        }

        $this->delimiter = $delimiter;
    }
}
