<?php
/*
* File: ImapProtocol.php
* Category: Protocol
* Author: M.Goldenbaum
* Created: 16.09.20 18:27
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Connection\Protocols;

use Exception;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;

/**
 * Class ImapProtocol
 *
 * @package Webklex\PHPIMAP\Connection\Protocols
 *
 * @reference https://www.rfc-editor.org/rfc/rfc2087.txt
 */
class ImapProtocol extends Protocol {

    /**
     * Request noun
     * @var int
     */
    protected int $noun = 0;

    /**
     * Untagged responses received while IDLE was started or ended
     * @var array<int, string>
     */
    protected array $idle_untagged = [];

    /**
     * Imap constructor.
     * @param Config $config
     * @param bool $cert_validation set to false to skip SSL certificate validation
     * @param mixed $encryption Connection encryption method
     */
    public function __construct(Config $config, bool $cert_validation = true, mixed $encryption = false) {
        $this->config = $config;
        $this->setCertValidation($cert_validation);
        $this->encryption = $encryption;
    }

    /**
     * Handle the class destruction / tear down
     */
    public function __destruct() {
        $this->logout();
    }

    /**
     * Open connection to IMAP server
     * @param string $host hostname or IP address of IMAP server
     * @param int|null $port of IMAP server, default is 143 and 993 for ssl
     *
     * @throws ConnectionFailedException
     */
    public function connect(string $host, ?int $port = null): bool {
        $transport = 'tcp';
        $encryption = '';

        if ($this->encryption) {
            $encryption = strtolower($this->encryption);
            if (in_array($encryption, ['ssl', 'tls'])) {
                $transport = $encryption;
                $port = $port === null ? 993 : $port;
            }
        }
        $port = $port === null ? 143 : $port;
        try {
            $response = new Response(0, $this->debug);
            $this->stream = $this->createStream($transport, $host, $port, $this->connection_timeout);
            if (!$this->stream || !$this->assumedNextLine($response, '* OK')) {
                throw new ConnectionFailedException('connection refused');
            }
            if ($encryption == 'starttls') {
                $this->enableStartTls();
            }
        } catch (Exception $e) {
            throw new ConnectionFailedException('connection failed', 0, $e);
        }
        return true;
    }

    /**
     * Check if the current session is connected
     *
     * @return bool
     */
    public function connected(): bool {
        if ((bool)$this->stream) {
            try {
                $this->requestAndResponse('NOOP');
                return true;
            } catch (ImapServerErrorException|ImapBadRequestException|RuntimeException) {
                // NO / BAD / BYE, an answer that is no IMAP status or a lost connection
                return false;
            }
        }
        return false;
    }

    /**
     * Enable tls on the current connection
     *
     * @throws ConnectionFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    protected function enableStartTls(): void {
        $response = $this->requestAndResponse('STARTTLS');
        $result = $response->successful() && stream_socket_enable_crypto($this->stream, true, $this->getCryptoMethod());
        if (!$result) {
            throw new ConnectionFailedException('failed to enable TLS');
        }
    }

    /**
     * Get the next line from stream
     *
     * @return string next line
     * @throws RuntimeException
     */
    public function nextLine(Response $response): string {
        $line = fgets($this->stream);
        if ($line === false || $line === '') {
            throw new RuntimeException('empty response');
        }
        $response->addResponse($line);
        if ($this->debug) echo "<< " . $line;
        return $line;
    }

    /**
     * Get the next line and check if it starts with a given string
     * @param Response $response
     * @param string $start
     *
     * @return bool
     * @throws RuntimeException
     */
    protected function assumedNextLine(Response $response, string $start): bool {
        return str_starts_with($this->nextLine($response), $start);
    }

    /**
     * Get the next line and check if it starts with a given string
     * The server can send untagged status updates starting with '*' if we are not looking for a status update,
     * the untagged lines will be ignored.
     *
     * @param Response $response
     * @param string $start
     *
     * @return bool
     * @throws RuntimeException
     */
    protected function assumedNextLineIgnoreUntagged(Response $response, string $start): bool {
        do {
            $line = $this->nextLine($response);
        } while (!(str_starts_with($start, '*')) && $this->isUntaggedLine($line));

        return str_starts_with($line, $start);
    }

    /**
     * Get the next line and split the tag
     * @param string|null $tag reference tag
     *
     * @return string next line
     * @throws RuntimeException
     */
    protected function nextTaggedLine(Response $response, ?string &$tag): string {
        $line = $this->nextLine($response);
        if (str_contains($line, ' ')) {
            list($tag, $line) = explode(' ', $line, 2);
        }

        return $line ?? '';
    }

    /**
     * Get the next line and split the tag
     * The server can send untagged status updates starting with '*', the untagged lines will be ignored.
     *
     * @param string|null $tag reference tag
     *
     * @return string next line
     * @throws RuntimeException
     */
    protected function nextTaggedLineIgnoreUntagged(Response $response, &$tag): string {
        do {
            $line = $this->nextLine($response);
        } while ($this->isUntaggedLine($line));

        list($tag, $line) = explode(' ', $line, 2);

        return $line;
    }

    /**
     * Get the next line and check if it contains a given string and split the tag
     * @param Response $response
     * @param string $start
     * @param $tag
     *
     * @return bool
     * @throws RuntimeException
     */
    protected function assumedNextTaggedLine(Response $response, string $start, &$tag): bool {
        return str_starts_with($this->nextTaggedLine($response, $tag), $start);
    }

    /**
     * Get the next line and check if it contains a given string and split the tag
     * @param string $start
     * @param $tag
     *
     * @return bool
     * @throws RuntimeException
     */
    protected function assumedNextTaggedLineIgnoreUntagged(Response $response, string $start, &$tag): bool {
        $line = $this->nextTaggedLineIgnoreUntagged($response, $tag);
        return str_starts_with($line, $start);
    }

    /**
     * RFC3501 - 2.2.2
     * Data transmitted by the server to the client and status responses
     * that do not indicate command completion are prefixed with the token
     * "*", and are called untagged responses.
     *
     * @param string $line
     * @return bool
     */
    protected function isUntaggedLine(string $line) : bool {
        return str_starts_with($line, '* ');
    }

    /**
     * Split a given line in values. A value is literal of any form or a list
     * @param Response $response
     * @param string $line
     *
     * @return array
     * @throws RuntimeException
     */
    protected function decodeLine(Response $response, string $line): array {
        $tokens = [];
        $stack = [];

        //  replace any trailing <NL> including spaces with a single space
        $line = rtrim($line) . ' ';
        while (($pos = strpos($line, ' ')) !== false) {
            $token = substr($line, 0, $pos);
            if (!strlen($token)) {
                $line = substr($line, $pos + 1);
                continue;
            }
            while ($token !== '' && $token[0] == '(') {
                $stack[] = $tokens;
                $tokens = [];
                $token = substr($token, 1);
            }
            if ($token !== '' && $token[0] != '"' && $token[0] != '{') {
                // A section spec may contain spaces and parentheses: BODY[HEADER.FIELDS (SUBJECT FROM)] is one
                // atom. A bracket at the start ("[UIDNEXT 5]" response code) is split as before.
                $open = strpos($token, '[');
                if ($open !== false && $open > 0 && !str_contains(substr($token, $open), ']')) {
                    $close = strpos($line, ']', $pos);
                    $next = $close === false ? false : strpos($line, ' ', $close);
                    if ($next !== false) {
                        $token .= substr($line, $pos, $next - $pos);
                        $pos = $next;
                    }
                }
                // An atom directly followed by another list, e.g. the end of a body part in a multipart
                // BODYSTRUCTURE: 23)("TEXT" - the rest after the closing parentheses is the next token
                $section_end = strrpos($token, ']');
                $close = $stack ? strpos($token, ')', $section_end === false ? 0 : $section_end) : false;
                if ($close !== false) {
                    $end = $close + strspn($token, ')', $close);
                    if ($end < strlen($token)) {
                        $line = substr($token, $end) . substr($line, $pos);
                        $token = substr($token, 0, $end);
                        $pos = -1;
                    }
                }
            }
            if ($token !== '' && $token[0] == '"') {
                // A quoted string ends at a space, at the end of the line or at a closing
                // parenthesis: (UID 1 INTERNALDATE "22-Oct-2026 10:00:00 +0000").
                // A backslash escapes the next character, so \" never ends the string.
                if (preg_match('%^\(*"((?:[^"\\\\]|\\\\.|")*?)"(?=[ )]|$)%', $line, $matches)) {
                    $tokens[] = $matches[1];
                    $line = substr($line, strlen($matches[0]));
                    // close the lists ending right after the quoted string
                    while ($stack && str_starts_with($line, ')')) {
                        $child = $tokens;
                        $tokens = array_pop($stack);
                        $tokens[] = $child;
                        $line = substr($line, 1);
                    }
                    continue;
                }
            }
            if ($token !== '' && $token[0] == '{') {
                $endPos = strpos($token, '}');
                $chars = substr($token, 1, $endPos - 1);
                if (is_numeric($chars)) {
                    $token = '';
                    while (strlen($token) < $chars) {
                        $token .= $this->nextLine($response);
                    }
                    $line = '';
                    if (strlen($token) > $chars) {
                        $line = substr($token, $chars);
                        $token = substr($token, 0, $chars);
                    } else {
                        $line .= $this->nextLine($response);
                    }
                    $tokens[] = $token;
                    $line = trim($line) . ' ';
                    continue;
                }
            }
            if ($stack && $token !== '' && $token[strlen($token) - 1] == ')') {
                // closing braces are not separated by spaces, so we need to count them
                $braces = strlen($token);
                $token = rtrim($token, ')');
                // only count braces if more than one
                $braces -= strlen($token) + 1;
                // only add if token had more than just closing braces
                if (rtrim($token) != '') {
                    $tokens[] = rtrim($token);
                }
                $token = $tokens;
                $tokens = array_pop($stack);
                // special handling if more than one closing brace
                while ($braces-- > 0) {
                    $tokens[] = $token;
                    $token = $tokens;
                    $tokens = array_pop($stack);
                }
            }
            $tokens[] = $token;
            $line = substr($line, $pos + 1);
        }

        // maybe the server forgot to send some closing braces
        while ($stack) {
            $child = $tokens;
            $tokens = array_pop($stack);
            $tokens[] = $child;
        }

        return $tokens;
    }

    /**
     * Read abd decode a response "line"
     * @param Response $response
     * @param array|string $tokens to decode
     * @param string $wantedTag targeted tag
     * @param bool $dontParse if true only the unparsed line is returned in $tokens
     *
     * @return bool
     * @throws RuntimeException
     */
    public function readLine(Response $response, array|string &$tokens = [], string $wantedTag = '*', bool $dontParse = false): bool {
        $line = $this->nextTaggedLine($response, $tag); // get next tag
        if (!$dontParse) {
            $tokens = $this->decodeLine($response, $line);
        } else {
            $tokens = $line;
        }

        // if tag is wanted tag we might be at the end of a multiline response
        return $tag == $wantedTag;
    }

    /**
     * Read all lines of response until given tag is found
     * @param Response $response
     * @param string $tag request tag
     * @param bool $dontParse if true every line is returned unparsed instead of the decoded tokens
     *
     * @return array
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function readResponse(Response $response, string $tag, bool $dontParse = false): array {
        $lines = [];
        $tokens = ""; // define $tokens variable before first use
        do {
            $readAll = $this->readLine($response, $tokens, $tag, $dontParse);
            $lines[] = $tokens;
        } while (!$readAll);

        $original = $tokens;
        if ($dontParse) {
            // First two chars are still needed for the response code
            $tokens = [trim(substr($tokens, 0, 3))];
        }

        $original = is_array($original) ? $original : [$original];


        // last line has response code
        $status = is_string($tokens[0] ?? null) ? $tokens[0] : '';
        if ($status == 'OK') {
            return $lines ?: [true];
        } elseif ($status == 'NO' || $status == 'BAD' || $status == 'BYE') {
            throw new ImapServerErrorException($this->stringifyArray($original));
        }

        throw new ImapBadRequestException($this->stringifyArray($original));
    }

    /**
     * Convert an array to a string
     * @param array $arr array to stringify
     *
     * @return string stringified array
     */
    private function stringifyArray(array $arr): string {
        $string = "";
        foreach ($arr as $value) {
            if (is_array($value)) {
                $string .= "(" . $this->stringifyArray($value) . ")";
            } else {
                $string .= $value . " ";
            }
        }
        return $string;
    }

    /**
     * Send a new request
     * @param string $command
     * @param array $tokens additional parameters to command, use escapeString() to prepare
     * @param string|null $tag provide a tag otherwise an autogenerated is returned
     *
     * @return Response
     * @throws RuntimeException
     */
    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response {
        if (!$tag) {
            $this->noun++;
            $tag = 'TAG' . $this->noun;
        }

        $line = $tag . ' ' . $command;

        $response = new Response($this->noun, $this->debug);

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $this->write($response, $line . ' ' . $token[0]);
                if (!$this->assumedNextLine($response, '+ ')) {
                    throw new RuntimeException('failed to send literal string');
                }
                $line = $token[1];
            } else {
                $line .= ' ' . $token;
            }
        }
        $this->write($response, $line);

        return $response;
    }

    /**
     * Write data to the current stream
     * @param Response $response
     * @param string $data
     *
     * @return void
     * @throws RuntimeException
     */
    public function write(Response $response, string $data): void {
        $command = $data . "\r\n";
        if ($this->debug) echo ">> " . $this->redactCredentials($command) . "\n";

        $response->addCommand($command);

        // A closed connection must surface as RuntimeException (connected() relies on it) - not as a PHP
        // warning, which error handlers such as Laravel's or PHPUnit's turn into a different exception
        $written = is_resource($this->stream) ? @fwrite($this->stream, $command) : false;
        if ($written === false || ($written === 0 && $command !== "")) {
            throw new RuntimeException('failed to write - connection closed?');
        }
    }

    /**
     * Redact passwords and auth tokens from a command line before it is echoed in debug mode
     * @param string $command
     *
     * @return string
     */
    protected function redactCredentials(string $command): string {
        // LOGIN "user" "password" -> keep the user, mask the password
        $command = preg_replace('/^(TAG\d+ LOGIN\s+("[^"]*"|\S+)\s+).*$/is', '$1"***"', $command);
        // AUTHENTICATE <mechanism> <base64 credentials> -> mask the credentials
        $command = preg_replace('/^(TAG\d+ AUTHENTICATE\s+\S+\s+).*$/is', '$1***', $command);
        return $command;
    }

    /**
     * Send a request and get response at once
     *
     * @param string $command
     * @param array $tokens parameters as in sendRequest()
     * @param bool $dontParse if true unparsed lines are returned instead of tokens
     *
     * @return Response response as in readResponse()
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function requestAndResponse(string $command, array $tokens = [], bool $dontParse = false): Response {
        $response = $this->sendRequest($command, $tokens, $tag);
        $response->setResult($this->readResponse($response, $tag, $dontParse));

        return $response;
    }

    /**
     * Escape one or more literals i.e. for sendRequest
     * @param array|string $string the literal/-s
     *
     * @return string|array escape literals, literals with newline ar returned
     *                      as array('{size}', 'string');
     */
    public function escapeString(array|string $string): array|string {
        if (func_num_args() < 2) {
            if (str_contains($string, "\n") || str_contains($string, "\r")) {
                return ['{' . strlen($string) . '}', $string];
            } else {
                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
            }
        }
        $result = [];
        foreach (func_get_args() as $string) {
            $result[] = $this->escapeString($string);
        }
        return $result;
    }

    /**
     * Escape a list with literals or lists
     * @param array $list list with literals or lists as PHP array
     *
     * @return string escaped list for imap
     */
    public function escapeList(array $list): string {
        $result = [];
        foreach ($list as $v) {
            if (!is_array($v)) {
                $result[] = $v;
                continue;
            }
            $result[] = $this->escapeList($v);
        }
        return '(' . implode(' ', $result) . ')';
    }

    /**
     * Login to a new session.
     *
     * @param string $user username
     * @param string $password password
     *
     * @return Response
     * @throws AuthFailedException
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     */
    public function login(string $user, string $password): Response {
        try {
            $command = 'LOGIN';
            $params = $this->escapeString($user, $password);

            return $this->requestAndResponse($command, $params, true);
        } catch (RuntimeException $e) {
            throw new AuthFailedException("failed to authenticate", 0, $e);
        }
    }

    /**
     * Authenticate your current IMAP session.
     * @param string $user username
     * @param string $token access token
     *
     * @return Response
     * @throws AuthFailedException
     */
    public function authenticate(string $user, string $token): Response {
        try {
            $authenticateParams = ['XOAUTH2', base64_encode("user=$user\1auth=Bearer $token\1\1")];
            $response = $this->sendRequest('AUTHENTICATE', $authenticateParams, $request_tag);

            while (true) {
                $tag = null;
                $tokens = $this->nextTaggedLine($response, $tag);
                if ($tag === '+') {
                    // try to log the challenge somewhere where it can be found
                    error_log("got an extra server challenge: $tokens");
                    // RFC 4959 / RFC 3501 6.2.2: answer the challenge (an error for XOAUTH2) with an empty
                    // line - the server then completes the command with a tagged NO
                    $this->write($response, '');
                } elseif ($tag !== $request_tag) {
                    // untagged data (e.g. "* CAPABILITY ...") - an untagged "* OK" is no completion either
                    continue;
                } elseif (preg_match("/^OK\\b/i", $tokens)) {
                    return $response->setResult([$tokens]);
                } else {
                    // NO, BAD or anything else completes the command as a failure
                    error_log("got failure response: $tokens");
                    return $response->addError("got failure response: $tokens");
                }
            }
        } catch (RuntimeException $e) {
            throw new AuthFailedException("failed to authenticate", 0, $e);
        }
    }

    /**
     * Logout of imap server
     *
     * @return Response
     */
    public function logout(): Response {
        if (!$this->stream) {
            $this->reset();
            return new Response(0, $this->debug);
        } elseif ($this->meta()["timed_out"]) {
            $this->reset();
            return new Response(0, $this->debug);
        }

        $result = null;
        try {
            $result = $this->requestAndResponse('LOGOUT', [], true);
            fclose($this->stream);
        } catch (Throwable) {
        }

        $this->reset();

        return $result ?? new Response(0, $this->debug);
    }

    /**
     * Reset the current stream and uid cache
     *
     * @return void
     */
    public function reset(): void {
        $this->stream = null;
        $this->uid_cache = [];
    }

    /**
     * Get an array of available capabilities
     *
     * @return Response list of capabilities
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     * @throws ResponseException
     */
    public function getCapabilities(): Response {
        $response = $this->requestAndResponse('CAPABILITY');

        if (!$response->getResponse()) return $response;

        return $response->setResult($response->validatedData()[0]);
    }

    /**
     * Examine and select have the same response.
     * @param string $command can be 'EXAMINE' or 'SELECT'
     * @param string $folder target folder
     *
     * @return Response
     * @throws RuntimeException
     */
    public function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX'): Response {
        $response = $this->sendRequest($command, [$this->escapeString($folder)], $tag);

        $result = [];
        $tokens = []; // define $tokens variable before first use
        while (!$this->readLine($response, $tokens, $tag)) {
            if (($tokens[0] ?? null) === 'FLAGS') {
                array_shift($tokens);
                $result['flags'] = $tokens;
                continue;
            }
            $value = $tokens[2] ?? null;
            switch ($tokens[1] ?? null) {
                case 'EXISTS':
                case 'RECENT':
                    $result[strtolower($tokens[1])] = is_string($tokens[0]) ? (int)$tokens[0] : 0;
                    break;
                case '[UIDVALIDITY':
                    $result['uidvalidity'] = is_string($value) ? (int)$value : 0;
                    break;
                case '[UIDNEXT':
                    $result['uidnext'] = is_string($value) ? (int)$value : 0;
                    break;
                case '[UNSEEN':
                    $result['unseen'] = is_string($value) ? (int)$value : 0;
                    break;
                case '[NONEXISTENT]':
                    throw new RuntimeException("folder doesn't exist");
                default:
                    // ignore
                    break;
            }
        }

        $response->setResult($result);

        if (($tokens[0] ?? null) !== 'OK') {
            $response->addError("request failed");
        }
        return $response;
    }

    /**
     * Change the current folder
     * @param string $folder change to this folder
     *
     * @return Response see examineOrSelect()
     * @throws RuntimeException
     */
    public function selectFolder(string $folder = 'INBOX'): Response {
        $this->uid_cache = [];

        return $this->examineOrSelect('SELECT', $folder);
    }

    /**
     * Examine a given folder
     * @param string $folder examine this folder
     *
     * @return Response see examineOrSelect()
     * @throws RuntimeException
     */
    public function examineFolder(string $folder = 'INBOX'): Response {
        return $this->examineOrSelect('EXAMINE', $folder);
    }

    /**
     * Get the status of a given folder
     *
     * @param string $folder
     * @param string[] $arguments
     * @return Response list of STATUS items
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws ResponseException
     * @throws RuntimeException
     */
    public function folderStatus(string $folder = 'INBOX', $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): Response {
        $response = $this->requestAndResponse('STATUS', [$this->escapeString($folder), $this->escapeList($arguments)]);
        $data = $response->validatedData();

        // "* STATUS <mailbox> (<items>)" - other untagged responses (e.g. EXISTS for the selected mailbox,
        // RFC 3501 7) may come before or after it
        $items = null;
        foreach ((array)$data as $line) {
            if (is_array($line) && is_string($line[0] ?? null) && strtoupper($line[0]) === 'STATUS' && is_array($line[2] ?? null)) {
                $items = $line[2];
                break;
            }
        }
        if ($items === null) {
            throw new RuntimeException("folder status could not be fetched");
        }

        $result = [];
        $key = null;
        foreach ($items as $value) {
            if (is_array($value)) {
                // no valid STATUS item value
                $key = null;
                continue;
            }
            if ($key === null) {
                $key = $value;
            } else {
                $result[strtolower($key)] = (int)$value;
                $key = null;
            }
        }

        $response->setResult($result);

        return $response;
    }

    /**
     * Fetch one or more items of one or more messages
     * @param array|string $items items to fetch [RFC822.HEADER, FLAGS, RFC822.TEXT, etc]
     * @param array|int $from message for items or start message if $to !== null
     * @param int|null $to if null only one message ($from) is fetched, else it's the
     *                             last message, INF means last message available
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     * message numbers instead.
     *
     * @return Response if only one item of one message is fetched it's returned as string
     *                      if items of one message are fetched it's returned as (name => value)
     *                      if one item of messages are fetched it's returned as (msgno => value)
     *                      if items of messages are fetched it's returned as (msgno => (name => value))
     * @throws RuntimeException
     */
    public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = IMAP::ST_UID): Response {
        if (is_array($from) && count($from) > 1) {
            $set = implode(',', $from);
        } elseif (is_array($from) && count($from) === 1) {
            $from = array_values($from);
            $set = $from[0] . ':' . $from[0];
        } elseif ($to === null) {
            $set = $from . ':' . $from;
        } elseif ($to == INF) {
            $set = $from . ':*';
        } else {
            $set = $from . ':' . (int)$to;
        }

        $items = (array)$items;
        $itemList = $this->escapeList($items);

        $response = $this->sendRequest($this->buildUIDCommand("FETCH", $uid), [$set, $itemList], $tag);
        $result = [];
        $tokens = []; // define $tokens variable before first use
        $itemMatches = static function($actual, $expected): bool {
            if (!is_string($actual)) {
                return false;
            }
            $actualNorm = strtoupper($actual);
            $expectedNorm = strtoupper((string) $expected);
            if ($actualNorm === $expectedNorm) {
                return true;
            }
            // IMAP may answer BODY[...] even when request used BODY.PEEK[...].
            return str_replace('.PEEK', '', $actualNorm) === str_replace('.PEEK', '', $expectedNorm);
        };
        while (!$this->readLine($response, $tokens, $tag)) {
            // ignore other responses - and FETCH responses without a valid item list
            // ("* <number> FETCH (<item> <value> ...)", RFC 3501 7.4.2)
            if (($tokens[1] ?? null) !== 'FETCH' || !is_array($tokens[2] ?? null) || !is_string($tokens[0] ?? null)) {
                continue;
            }
            $values = array_values($tokens[2]);
            $count = count($values);

            $id = $tokens[0];
            $data = [];

            // find the UID value; try the last elements, or search for it
            if ($uid === IMAP::ST_UID) {
                $uidKey = null;
                if ($count >= 2 && $values[$count - 2] === 'UID') {
                    $uidKey = $count - 1;
                } else {
                    for ($i = 0; $i + 1 < $count; $i += 2) {
                        if ($values[$i] === 'UID') {
                            $uidKey = $i + 1;
                            break;
                        }
                    }
                }
                if ($uidKey === null || !is_string($values[$uidKey])) {
                    continue;
                }
                $id = $values[$uidKey];
            }

            // ignore other messages
            if ($to === null && !is_array($from) && $id != $from) {
                continue;
            }

            if (count($items) == 1) {
                // if we only want one item we return that one directly
                $found = false;
                // the server may send other fields as well
                for ($i = 0; $i + 1 < $count; $i += 2) {
                    if ($itemMatches($values[$i], $items[0])) {
                        $data = $values[$i + 1];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            } else {
                for ($i = 0; $i + 1 < $count; $i += 2) {
                    if (is_string($values[$i])) {
                        $data[$values[$i]] = $values[$i + 1];
                    }
                }
            }

            // if we want only one message we can ignore everything else and just return
            if ($to === null && !is_array($from)) {
                // we still need to read all lines up to the tagged status line
                while (!$this->readLine($response, $tokens, $tag)) {
                    // skip the remaining lines
                }
                return $response->setResult($data);
            }
            $result[$id] = $data;
        }

        if ($to === null && !is_array($from)) {
            throw new RuntimeException('the single id was not found in response');
        }

        return $response->setResult($result);
    }

    /**
     * Fetch message body (without headers)
     * @param int|array $uids
     * @param string $rfc
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     * message numbers instead.
     * @param bool $peek true to fetch body using PEEK (do not set \Seen)
     *
     * @return Response
     * @throws RuntimeException
     */
    public function content(int|array $uids, string $rfc = "RFC822", int|string $uid = IMAP::ST_UID, bool $peek = false): Response {
        $rfc = $rfc ?? "RFC822";
        if ($peek && ($rfc === "RFC822" || $rfc === "BODY")) {
            // BODY.PEEK keeps the message unread at protocol level.
            $item = "BODY.PEEK[TEXT]";
        } else {
            $item = $rfc === "BODY" ? "BODY[TEXT]" : "$rfc.TEXT";
        }
        return $this->fetch([$item], is_array($uids) ? $uids : [$uids], null, $uid);
    }

    /**
     * Fetch message headers
     * @param int|array $uids
     * @param string $rfc
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     * message numbers instead.
     *
     * @return Response
     * @throws RuntimeException
     */
    public function headers(int|array $uids, string $rfc = "RFC822", int|string $uid = IMAP::ST_UID): Response {
        return $this->fetch(["$rfc.HEADER"], is_array($uids) ? $uids : [$uids], null, $uid);
    }

    /**
     * Fetch message flags
     * @param int|array $uids
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     * message numbers instead.
     *
     * @return Response
     * @throws RuntimeException
     */
    public function flags(int|array $uids, int|string $uid = IMAP::ST_UID): Response {
        return $this->fetch(["FLAGS"], is_array($uids) ? $uids : [$uids], null, $uid);
    }

    /**
     * Fetch message sizes
     * @param int|array $uids
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     * message numbers instead.
     *
     * @return Response
     * @throws RuntimeException
     */
    public function sizes(int|array $uids, int|string $uid = IMAP::ST_UID): Response {
        return $this->fetch(["RFC822.SIZE"], is_array($uids) ? $uids : [$uids], null, $uid);
    }

    /**
     * Get uid for a given id
     * @param int|null $id message number
     *
     * @return Response message number for given message or all messages as array
     * @throws MessageNotFoundException
     */
    public function getUid(?int $id = null): Response {
        $fetched = false;
        if (!$this->enable_uid_cache || empty($this->uid_cache) || count($this->uid_cache) <= 0) {
            $fetched = true;
            try {
                $this->setUidCache((array)$this->fetch('UID', 1, INF)->data()); // set cache for this folder
            } catch (RuntimeException) {
            }
        }
        $uids = $this->uid_cache;

        if ($id == null) {
            return Response::empty($this->debug)->setResult($uids)->setCanBeEmpty(true);
        }

        foreach ($uids as $k => $v) {
            if ($k == $id) {
                return Response::empty($this->debug)->setResult($v);
            }
        }

        // the cache may be outdated: clear it and run the method again (once - a freshly fetched list is final)
        if ($this->enable_uid_cache && $this->uid_cache && !$fetched) {
            $this->setUidCache(null);
            return $this->getUid($id);
        }

        throw new MessageNotFoundException('unique id not found');
    }

    /**
     * Get a message number for a uid
     * @param string $id uid
     *
     * @return Response message number
     * @throws MessageNotFoundException
     */
    public function getMessageNumber(string $id): Response {
        foreach ($this->getUid()->data() as $k => $v) {
            if ($v == $id) {
                return Response::empty($this->debug)->setResult((int)$k);
            }
        }

        throw new MessageNotFoundException('message number not found: ' . $id);
    }

    /**
     * Get a list of available folders
     *
     * @param string $reference mailbox reference for list
     * @param string $folder mailbox name match with wildcards
     *
     * @return Response folders that matched $folder as array(name => array('delimiter' => .., 'flags' => ..))
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function folders(string $reference = '', string $folder = '*'): Response {
        $response = $this->requestAndResponse('LIST', $this->escapeString($reference, $folder))->setCanBeEmpty(true);
        $list = $response->data();

        $result = [];
        if ($list[0] !== true) {
            foreach ($list as $item) {
                if (!is_array($item) || count($item) != 4 || $item[0] != 'LIST' || !is_string($item[2]) || !is_string($item[3])) {
                    continue;
                }
                $result[$this->unescapeQuoted($item[3])] = ['delimiter' => $this->unescapeQuoted($item[2]), 'flags' => $item[1]];
            }
        }

        return $response->setResult($result);
    }

    /**
     * Remove the escaping of a quoted string (RFC 3501 4.3: backslash escapes backslash and double quote)
     * @param string $value
     *
     * @return string
     */
    protected function unescapeQuoted(string $value): string {
        return preg_replace('/\\\\([\\\\"])/', '$1', $value);
    }

    /**
     * Manage flags
     *
     * @param array|string $flags flags to set, add or remove - see $mode
     * @param int $from message for items or start message if $to !== null
     * @param int|null $to if null only one message ($from) is fetched, else it's the
     *                             last message, INF means last message available
     * @param string|null $mode '+' to add flags, '-' to remove flags, everything else sets the flags as given
     * @param bool $silent if false the return values are the new flags for the wanted messages
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                             message numbers instead.
     * @param string|null $item command used to store a flag
     *
     * @return Response new flags if $silent is false, else true or false depending on success
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function store(
        array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, int|string $uid = IMAP::ST_UID, ?string $item = null
    ): Response {
        $flags = $this->escapeList(is_array($flags) ? $flags : [$flags]);
        $set = $this->buildSet($from, $to);

        $command = $this->buildUIDCommand("STORE", $uid);
        $prefix = in_array($mode, ['+', '-'], true) ? $mode : "";
        $item = $prefix . ($item === null ? "FLAGS" : $item) . ($silent ? '.SILENT' : "");

        $response = $this->requestAndResponse($command, [$set, $item, $flags], $silent);

        if ($silent) {
            return $response;
        }

        $result = [];
        foreach ($response->data() as $token) {
            // "* 2 FETCH (FLAGS (\Seen))" or "* 2 FETCH (UID 5 FLAGS (\Seen))" (RFC 3501 6.4.8, Dovecot)
            if (!is_array($token) || ($token[1] ?? null) !== 'FETCH' || !is_array($token[2] ?? null)) {
                continue;
            }
            $items = array_values($token[2]);
            for ($i = 0; $i + 1 < count($items); $i += 2) {
                if (is_string($items[$i]) && strtoupper($items[$i]) === 'FLAGS') {
                    $result[$token[0]] = $items[$i + 1];
                    break;
                }
            }
        }


        return $response->setResult($result);
    }

    /**
     * Append a new message to given folder
     *
     * @param string $folder name of target folder
     * @param string $message full message content
     * @param array|null $flags flags for new message
     * @param string|null $date date for new message
     *
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): Response {
        $tokens = [];
        $tokens[] = $this->escapeString($folder);
        if ($flags !== null) {
            $tokens[] = $this->escapeList($flags);
        }
        if ($date !== null) {
            $tokens[] = $this->escapeString($date);
        }
        // RFC 3501 6.3.11 / 9: the message is always sent as literal - escapeString() would quote a message
        // without line break, which servers reject
        $tokens[] = ['{' . strlen($message) . '}', $message];

        return $this->requestAndResponse('APPEND', $tokens, true);
    }

    /**
     * Copy a message set from current folder to another folder
     *
     * @param string $folder destination folder
     * @param $from
     * @param int|null $to if null only one message ($from) is fetched, else it's the
     *                        last message, INF means last message available
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead.
     *
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function copyMessage(string $folder, $from, ?int $to = null, int|string $uid = IMAP::ST_UID): Response {
        $set = $this->buildSet($from, $to);
        $command = $this->buildUIDCommand("COPY", $uid);

        return $this->requestAndResponse($command, [$set, $this->escapeString($folder)], true);
    }

    /**
     * Copy multiple messages to the target folder
     *
     * @param array $messages List of message identifiers
     * @param string $folder Destination folder
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead.
     *
     * @return Response Tokens if operation successful, false if an error occurred
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function copyManyMessages(array $messages, string $folder, int|string $uid = IMAP::ST_UID): Response {
        $command = $this->buildUIDCommand("COPY", $uid);

        $set = implode(',', $messages);
        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse($command, $tokens, true);
    }

    /**
     * Move a message set from current folder to another folder
     *
     * @param string $folder destination folder
     * @param $from
     * @param int|null $to if null only one message ($from) is fetched, else it's the
     *                         last message, INF means last message available
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                         message numbers instead.
     *
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function moveMessage(string $folder, $from, ?int $to = null, int|string $uid = IMAP::ST_UID): Response {
        $set = $this->buildSet($from, $to);
        $command = $this->buildUIDCommand("MOVE", $uid);

        try {
            return $this->requestAndResponse($command, [$set, $this->escapeString($folder)], true);
        } catch (ImapServerErrorException $e) {
            if (!$this->isUnknownCommand($e)) {
                throw $e;
            }
        }
        // RFC4315 fallback to COPY, STORE and EXPUNGE.
        // Required for cases where MOVE isn't supported by the server. So we copy the message to the target folder,
        // mark the original message as deleted and expunge the mailbox.
        // See the following links for more information:
        // - https://github.com/freescout-help-desk/freescout/issues/4313
        // - https://github.com/Webklex/php-imap/issues/123
        $this->copyMessage($folder, $from, $to, $uid);
        $this->store(['\Deleted'], $from, $to, '+', true, $uid);
        return $this->expunge();
    }

    /**
     * Was a command rejected because the server does not know it? Servers answer an unknown command
     * (e.g. MOVE without RFC 6851 support) with a tagged BAD - NO means the command was understood but failed.
     * @param ImapServerErrorException $e
     *
     * @return bool
     */
    protected function isUnknownCommand(ImapServerErrorException $e): bool {
        return str_starts_with(ltrim($e->getMessage()), 'BAD');
    }

    /**
     * Move multiple messages to the target folder
     *
     * @param array $messages List of message identifiers
     * @param string $folder Destination folder
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead.
     *
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function moveManyMessages(array $messages, string $folder, int|string $uid = IMAP::ST_UID): Response {
        $command = $this->buildUIDCommand("MOVE", $uid);
        $set = implode(',', $messages);
        $tokens = [$set, $this->escapeString($folder)];

        try {
            return $this->requestAndResponse($command, $tokens, true);
        } catch (ImapServerErrorException $e) {
            if (!$this->isUnknownCommand($e)) {
                throw $e;
            }
        }
        // RFC4315 fallback to COPY, STORE and EXPUNGE (see moveMessage())
        $this->copyManyMessages($messages, $folder, $uid);
        foreach ($messages as $message) {
            $this->store(['\Deleted'], $message, $message, '+', true, $uid);
        }
        return $this->expunge();
    }

    /**
     * Exchange identification information
     * Ref.: https://datatracker.ietf.org/doc/html/rfc2971
     *
     * @param array|null $ids
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function ID($ids = null): Response {
        $token = "NIL";
        if (is_array($ids) && !empty($ids)) {
            $token = "(";
            foreach ($ids as $id) {
                $token .= '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], (string)$id) . '" ';
            }
            $token = rtrim($token) . ")";
        }

        return $this->requestAndResponse("ID", [$token], true);
    }

    /**
     * Create a new folder (and parent folders if needed)
     *
     * @param string $folder folder name
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function createFolder(string $folder): Response {
        return $this->requestAndResponse('CREATE', [$this->escapeString($folder)], true);
    }

    /**
     * Rename an existing folder
     *
     * @param string $old old name
     * @param string $new new name
     *
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function renameFolder(string $old, string $new): Response {
        return $this->requestAndResponse('RENAME', $this->escapeString($old, $new), true);
    }

    /**
     * Delete a folder
     *
     * @param string $folder folder name
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function deleteFolder(string $folder): Response {
        return $this->requestAndResponse('DELETE', [$this->escapeString($folder)], true);
    }

    /**
     * Subscribe to a folder
     *
     * @param string $folder folder name
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function subscribeFolder(string $folder): Response {
        return $this->requestAndResponse('SUBSCRIBE', [$this->escapeString($folder)], true);
    }

    /**
     * Unsubscribe from a folder
     *
     * @param string $folder folder name
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function unsubscribeFolder(string $folder): Response {
        return $this->requestAndResponse('UNSUBSCRIBE', [$this->escapeString($folder)], true);
    }

    /**
     * Apply session saved changes to the server
     *
     * @return Response
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function expunge(): Response {
        $this->uid_cache = [];
        return $this->requestAndResponse('EXPUNGE');
    }

    /**
     * Send noop command
     *
     * @return Response
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function noop(): Response {
        return $this->requestAndResponse('NOOP');
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox
     *
     * @param $username
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     *
     * @Doc https://www.rfc-editor.org/rfc/rfc2087.txt
     */
    public function getQuota($username): Response {
        $command = "GETQUOTA";
        $params = [$this->escapeString('#user/' . $username)];

        return $this->requestAndResponse($command, $params);
    }

    /**
     * Retrieve the quota settings per user
     *
     * @param string $quota_root
     * @return Response
     *
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     *
     * @Doc https://www.rfc-editor.org/rfc/rfc2087.txt
     */
    public function getQuotaRoot(string $quota_root = 'INBOX'): Response {
        $command = "GETQUOTAROOT";
        $params = [$this->escapeString($quota_root)];

        return $this->requestAndResponse($command, $params);
    }

    /**
     * Send idle command
     *
     * Untagged responses the server sends before the continuation (e.g. "* 3 EXISTS" for a message that
     * arrived since the last command) are kept and can be fetched with pullIdleUntaggedResponses().
     *
     * @throws RuntimeException
     */
    public function idle(): void {
        $response = $this->sendRequest("IDLE");
        while ($this->isUntaggedLine($line = $this->nextLine($response))) {
            $this->idle_untagged[] = $line;
        }
        if (!str_starts_with($line, '+ ')) {
            throw new RuntimeException('idle failed');
        }
    }

    /**
     * Send done command
     *
     * Untagged responses the server sends before completing the IDLE command (e.g. "* 3 EXISTS" for a
     * message that arrived while DONE was on its way, or servers that only report changes when IDLE ends)
     * are kept and can be fetched with pullIdleUntaggedResponses().
     *
     * @throws RuntimeException
     */
    public function done(): bool {
        $response = new Response($this->noun, $this->debug);
        $this->write($response, "DONE");
        while ($this->isUntaggedLine($line = $this->nextLine($response))) {
            $this->idle_untagged[] = $line;
        }
        $parts = explode(' ', $line, 2);
        if (!str_contains($parts[1] ?? '', 'OK')) {
            throw new RuntimeException('done failed');
        }
        return true;
    }

    /**
     * Get and clear the untagged responses received while IDLE was started or ended (see idle() and done()).
     * Without this, an EXISTS that arrives during the IDLE/DONE exchange would be lost.
     *
     * @return array<int, string>
     */
    public function pullIdleUntaggedResponses(): array {
        $lines = $this->idle_untagged;
        $this->idle_untagged = [];
        return $lines;
    }

    /**
     * Search for matching messages
     *
     * @param array $params
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                 message numbers instead.
     *
     * @return Response message ids
     * @throws ImapBadRequestException
     * @throws ImapServerErrorException
     * @throws RuntimeException
     */
    public function search(array $params, int|string $uid = IMAP::ST_UID): Response {
        $command = $this->buildUIDCommand("SEARCH", $uid);
        $response = $this->requestAndResponse($command, $params)->setCanBeEmpty(true);

        foreach ($response->data() as $ids) {
            if ($ids[0] === 'SEARCH') {
                array_shift($ids);
                return $response->setResult($ids);
            }
        }

        return $response;
    }

    /**
     * Get a message overview
     * @param string $sequence
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     * message numbers instead.
     *
     * @return Response
     * @throws RuntimeException
     * @throws MessageNotFoundException
     * @throws InvalidMessageDateException
     */
    public function overview(string $sequence, int|string $uid = IMAP::ST_UID): Response {
        $result = [];
        list($from, $to) = explode(":", $sequence);

        $response = $this->getUid();
        $ids = [];
        foreach ($response->data() as $msgn => $v) {
            $id = $uid === IMAP::ST_UID ? $v : $msgn;
            if (($to >= $id && $from <= $id) || ($to === "*" && $from <= $id)) {
                $ids[] = $id;
            }
        }
        if (!empty($ids)) {
            $headers = $this->headers($ids, "RFC822", $uid);
            $response->stack($headers);
            foreach ($headers->data() as $id => $raw_header) {
                $result[$id] = (new Header($raw_header, $this->config))->getAttributes();
            }
        }
        return $response->setResult($result)->setCanBeEmpty(true);
    }

    /**
     * Enable the debug mode
     *
     * @return void
     */
    public function enableDebug(): void {
        $this->debug = true;
    }

    /**
     * Disable the debug mode
     *
     * @return void
     */
    public function disableDebug(): void {
        $this->debug = false;
    }

    /**
     * Build a valid UID number set
     * @param $from
     * @param null $to
     *
     * @return int|string
     */
    public function buildSet($from, $to = null): int|string {
        $set = (int)$from;
        if ($to !== null) {
            $set .= ':' . ($to == INF ? '*' : (int)$to);
        }
        return $set;
    }
}
