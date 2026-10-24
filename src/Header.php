<?php
/*
* File: Header.php
* Category: -
* Author: M.Goldenbaum
* Created: 17.09.20 20:38
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;


use Carbon\Carbon;
use Webklex\PHPIMAP\Decoder\DecoderInterface;
use Webklex\PHPIMAP\Exceptions\DecoderNotFoundException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Exceptions\SpoofingAttemptDetectedException;

/**
 * Class Header
 *
 * @package Webklex\PHPIMAP
 */
class Header {

    /**
     * Raw header
     *
     * @var string $raw
     */
    public string $raw = "";

    /**
     * Attribute holder
     *
     * @var Attribute[]|array $attributes
     */
    protected array $attributes = [];

    /**
     * Config holder
     *
     * @var Config $config
     */
    protected Config $config;

    /**
     * Config holder
     *
     * @var array $options
     */
    protected array $options = [];

    /**
     * Decoder instance
     *
     * @var DecoderInterface $decoder
     */
    protected DecoderInterface $decoder;

    /**
     * Header constructor.
     * @param Config $config
     * @param string $raw_header
     *
     * @throws InvalidMessageDateException
     * @throws DecoderNotFoundException
     */
    public function __construct(string $raw_header, Config $config) {
        $this->decoder = $config->getDecoder("header");
        $this->raw = $raw_header;
        $this->config = $config;
        $this->options = $this->config->get('options');
        $this->parse();
    }

    /**
     * Call dynamic attribute setter and getter methods
     * @param string $method
     * @param array $arguments
     *
     * @return Attribute|mixed
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments) {
        if (strtolower(substr($method, 0, 3)) === 'get') {
            // Insert the underscores *before* lowercasing, otherwise the CamelCase
            // lookahead never matches (e.g. getMessageId -> message_id).
            $name = strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', substr($method, 3)));

            if (in_array($name, array_keys($this->attributes))) {
                return $this->attributes[$name];
            }

        }

        throw new MethodNotFoundException("Method " . self::class . '::' . $method . '() is not supported');
    }

    /**
     * Magic getter
     * @param $name
     *
     * @return Attribute|null
     */
    public function __get($name) {
        return $this->get($name);
    }

    /**
     * Get a specific header attribute
     * @param $name
     *
     * @return Attribute
     */
    public function get($name): Attribute {
        $key = str_replace(["-", " "], "_", strtolower($name));
        if (isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }
        // attributes set under their raw name, e.g. fetch extensions like "X-GM-LABELS"
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }

        return new Attribute($key);
    }

    /**
     * Check if a specific attribute exists
     * @param string $name
     *
     * @return bool
     */
    public function has(string $name): bool {
        $key = str_replace(["-", " "], "_", strtolower($name));
        return isset($this->attributes[$key]) || isset($this->attributes[$name]);
    }

    /**
     * Set a specific attribute
     * @param string $name
     * @param array|mixed $value
     * @param boolean $strict
     *
     * @return Attribute|array
     */
    public function set(string $name, mixed $value, bool $strict = false): Attribute|array {
        if (isset($this->attributes[$name]) && $strict === false) {
            $this->attributes[$name]->add($value, true);
        } else {
            $this->attributes[$name] = new Attribute($name, $value);
        }

        return $this->attributes[$name];
    }

    /**
     * Perform a regex match all on the raw header and return the first result
     * @param $pattern
     *
     * @return mixed|null
     */
    public function find($pattern): mixed {
        if (preg_match_all($pattern, $this->raw, $matches)) {
            if (isset($matches[1])) {
                if (count($matches[1]) > 0) {
                    return $matches[1][0];
                }
            }
        }
        return null;
    }

    /**
     * Try to find a boundary if possible
     *
     * @return string|null
     */
    public function getBoundary(): ?string {
        $regex = $this->options["boundary"] ?? "/boundary=(.*?(?=;)|(.*))/i";

        // Only trust the boundary declared inside the Content-Type header (incl.
        // folded continuation lines). Searching the whole raw header would let a
        // crafted header such as "X-Evil: boundary=..." hijack the MIME parsing.
        $search = $this->raw;
        if (preg_match('/^content-type:(.*(?:\r?\n[ \t].*)*)/im', $this->raw, $ct)) {
            $search = $ct[1];
        }

        $boundary = null;
        if (preg_match($regex, $search, $match)) {
            $boundary = $match[1] ?? null;
        }

        if ($boundary === null || $boundary === "") {
            return null;
        }

        return $this->clearBoundaryString($boundary);
    }

    /**
     * Remove all unwanted chars from a given boundary
     * @param string $str
     *
     * @return string
     */
    private function clearBoundaryString(string $str): string {
        return trim(str_replace(['"', '\r', '\n', "\n", "\r", ";", "\s"], "", $str));
    }

    /**
     * Parse the raw headers
     *
     * @throws InvalidMessageDateException
     * @throws SpoofingAttemptDetectedException
     */
    protected function parse(): void {
        $header = $this->rfc822_parse_headers($this->raw);

        $this->extractAddresses($header);

        if (property_exists($header, 'subject')) {
            $this->set("subject", $this->decoder->decode($header->subject));
        }
        if (property_exists($header, 'references')) {
            $this->set("references", array_map(function($item) {
                return str_replace(['<', '>'], '', $item);
            }, explode(" ", $header->references)));
        }
        if (property_exists($header, 'message_id')) {
            $this->set("message_id", str_replace(['<', '>'], '', $header->message_id));
        }
        if (property_exists($header, 'in_reply_to')) {
            $this->set("in_reply_to", str_replace(['<', '>'], '', $header->in_reply_to));
        }

        $this->parseDate($header);
        foreach ($header as $key => $value) {
            $key = trim(rtrim(strtolower($key)));
            if (!isset($this->attributes[$key])) {
                $this->set($key, $value);
            }
        }

        $this->extractHeaderExtensions();
        $this->findPriority();

        if($this->config->get('security.detect_spoofing', true)) {
            // Detect spoofing
            $this->detectSpoofing();
        }
    }

    /**
     * Headers which contain address lists. They are always parsed by parseAddressList(), so the result does
     * not depend on ext-imap (see rfc822_parse_headers()).
     */
    private const ADDRESS_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply_to', 'sender', 'return_path', 'envelope_from', 'envelope_to', 'delivered_to'];

    /**
     * Address headers which additionally get a raw "<key>address" value (like imap_rfc822_parse_headers())
     */
    private const RAW_ADDRESS_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply_to', 'sender'];

    /**
     * Headers of which only the first occurrence counts (like imap_rfc822_parse_headers())
     */
    private const SINGLE_HEADERS = ['subject', 'date', 'message_id', 'in_reply_to', 'references'];

    /**
     * Parse mail headers from a string
     *
     * Works like imap_rfc822_parse_headers(): folded lines are unfolded, CRLF and bare LF line endings are
     * accepted, address headers become lists of objects (personal, mailbox, host) plus a raw "<key>address"
     * value, reply_to and sender default to from, and only the first subject / date / message-id /
     * in-reply-to / references header counts. Every other header is kept as string, or as array if it occurs
     * more than once. If ext-imap is available (and options.rfc822 is enabled) its values are used for the
     * non-address headers - they are the same. Address lists are always parsed by the library itself, because
     * c-client turns invalid lists into placeholder addresses ("Name Only" becomes "Name@UNKNOWN" plus
     * "UNEXPECTED_DATA_AFTER_ADDRESS@.SYNTAX-ERROR.") and rewrites the raw "<key>address" values.
     * @link https://php.net/manual/en/function.imap-rfc822-parse-headers.php
     * @param $raw_headers
     *
     * @return object
     */
    public function rfc822_parse_headers($raw_headers): object {
        $headers = [];
        $imap_headers = [];
        $raw_headers = (string)$raw_headers;
        if (extension_loaded('imap') && $this->options["rfc822"]) {
            $raw_imap_headers = (array)\imap_rfc822_parse_headers($raw_headers);
            foreach ($raw_imap_headers as $key => $values) {
                $key = strtolower(str_replace("-", "_", $key));
                if (in_array($key, self::ADDRESS_HEADERS) || in_array(substr($key, 0, -7), self::RAW_ADDRESS_HEADERS)) {
                    continue;
                }
                $imap_headers[$key] = $values;
            }
        }
        $lines = explode("\r\n", preg_replace("/\r\n[ \t]/", ' ', $raw_headers));
        $prev_header = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, "\t") || str_starts_with($line, " ")) {
                // a continuation line without a header line before (e.g. at the very beginning)
                $line = trim($line);
                if ($prev_header !== null && $line !== "") {
                    $last = count($headers[$prev_header]) - 1;
                    $headers[$prev_header][$last] = trim($headers[$prev_header][$last] . " " . $line);
                }
            } elseif (($pos = strpos($line, ":")) > 0) {
                $key = strtolower(str_replace("-", "_", trim(substr($line, 0, $pos))));
                $headers[$key][] = trim(substr($line, $pos + 1));
                $prev_header = $key;
            }
        }

        foreach ($headers as $key => $values) {
            $key = (string)$key;
            if (in_array($key, self::ADDRESS_HEADERS)) {
                $headers[$key] = $this->parseAddressList(implode(", ", $values));
                if (in_array($key, self::RAW_ADDRESS_HEADERS)) {
                    $headers[$key . "address"] = implode(", ", array_filter($values, function($value) {
                        return $value !== "";
                    }));
                }
                continue;
            }
            if (isset($imap_headers[$key])) {
                continue;
            }
            if (in_array($key, self::SINGLE_HEADERS)) {
                $headers[$key] = $values[0];
                continue;
            }
            $values = array_values(array_filter($values, function($value) {
                return $value !== "";
            }));
            $headers[$key] = match (count($values)) {
                0 => "",
                1 => $values[0],
                default => $values,
            };
        }

        // like c-client: without Reply-To / Sender the message is answered to / was sent by "from"
        if (!empty($headers["from"])) {
            foreach (["reply_to", "sender"] as $key) {
                if (empty($headers[$key])) {
                    $headers[$key] = $headers["from"];
                    $headers[$key . "address"] = $headers["fromaddress"];
                }
            }
        }

        return (object)array_merge($headers, $imap_headers);
    }

    /**
     * Try to extract the priority from a given raw header string
     */
    private function findPriority(): void {
        $priority = $this->get("x_priority");

        $priority = match ((int)"$priority") {
            IMAP::MESSAGE_PRIORITY_HIGHEST => IMAP::MESSAGE_PRIORITY_HIGHEST,
            IMAP::MESSAGE_PRIORITY_HIGH => IMAP::MESSAGE_PRIORITY_HIGH,
            IMAP::MESSAGE_PRIORITY_NORMAL => IMAP::MESSAGE_PRIORITY_NORMAL,
            IMAP::MESSAGE_PRIORITY_LOW => IMAP::MESSAGE_PRIORITY_LOW,
            IMAP::MESSAGE_PRIORITY_LOWEST => IMAP::MESSAGE_PRIORITY_LOWEST,
            default => IMAP::MESSAGE_PRIORITY_UNKNOWN,
        };

        $this->set("priority", $priority);
    }

    /**
     * Parse an address list (RFC 5322 3.4) into objects with the properties personal, mailbox and host
     *
     * - display names may be quoted ("Doe, John") and contain quoted-pairs, the quotes are removed
     * - a comment is used as display name if there is none: "a@b.c (Name)"
     * - group syntax: "Group: a@b.c, d@e.f;" yields the members; the group name is no address, so an empty
     *   group like "undisclosed-recipients:;" yields nothing
     * - ";" is accepted as separator outside of groups (Outlook style lists)
     * - an empty address "<>" (null sender) is kept with empty mailbox and host
     * - an address without host ("no_host") is kept as mailbox only
     * - "Name addr@host" without angle brackets uses the last word as address, an item without any address
     *   ("Name Only") is dropped
     * - the source route of "<@route:a@b.c>" is removed
     * @param string $list
     *
     * @return object[]
     */
    private function parseAddressList(string $list): array {
        if (extension_loaded('mailparse') && $this->options["rfc822"]) {
            $addresses = [];
            foreach (\mailparse_rfc822_parse_addresses($list) as $parsed_address) {
                if (isset($parsed_address['address'])) {
                    $mail_address = explode('@', $parsed_address['address']);
                    if (count($mail_address) == 2) {
                        $addresses[] = (object)[
                            "personal" => $parsed_address['display'] ?? '',
                            "mailbox"  => $mail_address[0],
                            "host"     => $mail_address[1],
                        ];
                    }
                }
            }

            return $addresses;
        }

        $addresses = [];
        foreach ($this->splitAddressList($list) as $item) {
            if (($address = $this->parseAddressListItem($item)) !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Split an address list at the top level separators, i.e. outside of quoted strings, comments and angle
     * brackets. Group names ("Group:") and group terminators (";") are removed.
     * @param string $list
     *
     * @return string[]
     */
    private function splitAddressList(string $list): array {
        $items = [];
        $current = "";
        $quoted = false;
        $comment = 0;
        $angle = false;
        $length = strlen($list);

        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];
            if ($char === "\\" && ($quoted || $comment > 0) && $i + 1 < $length) {
                $current .= $char . $list[++$i];
                continue;
            }
            if ($quoted) {
                $quoted = $char !== '"';
            } elseif ($comment > 0) {
                if ($char === "(") {
                    $comment++;
                } elseif ($char === ")") {
                    $comment--;
                }
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === "(") {
                $comment++;
            } elseif ($angle) {
                $angle = $char !== ">";
            } elseif ($char === "<") {
                $angle = true;
            } elseif ($char === "," || $char === ";") {
                $items[] = $current;
                $current = "";
                continue;
            } elseif ($char === ":") {
                // a group name - no address
                $current = "";
                continue;
            }
            $current .= $char;
        }
        $items[] = $current;

        return $items;
    }

    /**
     * Parse a single item of an address list
     * @param string $item
     *
     * @return object|null
     */
    private function parseAddressListItem(string $item): ?object {
        $phrase = "";
        $comments = [];
        $address = null;
        $length = strlen($item);

        for ($i = 0; $i < $length; $i++) {
            $char = $item[$i];
            if ($char === '"') {
                $value = "";
                for ($i++; $i < $length && $item[$i] !== '"'; $i++) {
                    if ($item[$i] === "\\" && $i + 1 < $length) {
                        $i++;
                    }
                    $value .= $item[$i];
                }
                if ($address === null) {
                    // mark quoted text, its whitespace is kept
                    $phrase .= "\x00" . $value . "\x00";
                } else {
                    $address .= '"' . $value . '"';
                }
            } elseif ($char === "(") {
                $depth = 1;
                $value = "";
                for ($i++; $i < $length; $i++) {
                    if ($item[$i] === "\\" && $i + 1 < $length) {
                        $value .= $item[++$i];
                        continue;
                    }
                    if ($item[$i] === "(") {
                        $depth++;
                    } elseif ($item[$i] === ")" && --$depth === 0) {
                        break;
                    }
                    $value .= $item[$i];
                }
                $comments[] = trim($value);
            } elseif ($char === "<" && $address === null) {
                $address = "";
            } elseif ($char === ">" && $address !== null) {
                // anything behind the angle address is ignored
                break;
            } elseif ($address !== null) {
                $address .= $char;
            } else {
                $phrase .= $char;
            }
        }

        if ($address === null) {
            // no angle address: the address is the phrase itself or its last word ("Name addr@host")
            // (whitespace inside of quoted parts does not separate words)
            $parts = explode("\x00", $phrase);
            foreach ($parts as $index => $part) {
                if ($index % 2 === 1) {
                    $parts[$index] = str_replace([" ", "\t"], "\x01", $part);
                }
            }
            $words = preg_split('/\s+/', implode("\x00", $parts), -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) === 0) {
                return null;
            }
            $address = str_replace(["\x00", "\x01"], ['"', " "], array_pop($words));
            if (count($words) > 0 && !str_contains($address, "@")) {
                return null;
            }
            $phrase = str_replace("\x01", " ", implode(" ", $words));
        }

        $address = trim($address);
        if (str_starts_with($address, "@") && ($pos = strrpos($address, ":")) !== false) {
            $address = substr($address, $pos + 1);
        }
        if (($pos = strrpos($address, "@")) !== false) {
            $mailbox = substr($address, 0, $pos);
            $host = substr($address, $pos + 1);
        } else {
            $mailbox = $address;
            $host = "";
        }
        if (strlen($mailbox) > 1 && str_starts_with($mailbox, '"') && str_ends_with($mailbox, '"')) {
            $mailbox = substr($mailbox, 1, -1);
        }

        // collapse whitespace outside of the quoted parts
        $parts = explode("\x00", $phrase);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = preg_replace('/\s+/', ' ', $part);
            }
        }
        $personal = trim(implode("", $parts));
        if ($personal === "" && count($comments) > 0) {
            $personal = implode(" ", $comments);
        }
        if (strlen($personal) > 1 && str_starts_with($personal, "'") && str_ends_with($personal, "'")) {
            $personal = substr($personal, 1, -1);
        }

        return (object)[
            "personal" => $personal,
            "mailbox"  => trim($mailbox),
            "host"     => trim($host),
        ];
    }

    /**
     * Extract a given part as address array from a given header
     * @param object $header
     */
    private function extractAddresses(object $header): void {
        foreach (self::ADDRESS_HEADERS as $key) {
            if (property_exists($header, $key)) {
                $this->set($key, $this->parseAddresses($header->$key));
            }
        }
    }

    /**
     * Turn parsed address objects into Address instances and decode their display names
     * @param array $list
     *
     * @return Address[]
     */
    private function parseAddresses(array $list): array {
        $addresses = [];

        foreach ($list as $item) {
            $address = (object)$item;

            if (!property_exists($address, 'mailbox')) {
                $address->mailbox = false;
            }
            if (!property_exists($address, 'host')) {
                $address->host = false;
            }
            if (!property_exists($address, 'personal')) {
                $address->personal = false;
            } else {
                $address->personal = trim((string)$this->decoder->decode((string)$address->personal));
            }

            if ($address->host == ".SYNTAX-ERROR.") {
                $address->host = "";
            }elseif ($address->host == "UNKNOWN") {
                $address->host = "";
            }
            if ($address->mailbox == "UNEXPECTED_DATA_AFTER_ADDRESS") {
                $address->mailbox = "";
            }elseif ($address->mailbox == "MISSING_MAILBOX_TERMINATOR") {
                $address->mailbox = "";
            }

            $addresses[] = new Address($address);
        }

        return $addresses;
    }

    /**
     * Search and extract potential header extensions
     */
    private function extractHeaderExtensions(): void {
        foreach ($this->attributes as $key => $value) {
            if (is_array($value)) {
                $value = implode(", ", $value);
            } else {
                $value = (string)$value;
            }
            // Only parse strings and don't parse any attributes like the user-agent.
            // Keys are normalized with underscores, so the exclusion list must use them
            // too (the previous "user-agent" entry never matched anything).
            if (!in_array($key, ["user_agent", "subject", "received"])) {
                if (str_contains($value, ";") && str_contains($value, "=")) {
                    $_attributes = $this->read_attribute($value);
                    foreach($_attributes as $_key => $_value) {
                        if($_value === "") {
                            $this->set($key, $_key);
                        }
                        if (!isset($this->attributes[$_key])) {
                            $this->set($_key, $_value);
                        }
                    }
                }
            }
        }
    }

    /**
     * Read a given attribute string
     * - this isn't pretty, but it works - feel free to improve :)
     * @param string $raw_attribute
     * @return array
     */
    private function read_attribute(string $raw_attribute): array {
        $attributes = [];
        $key = '';
        $value = '';
        $inside_word = false;
        $inside_key = true;
        $escaped = false;
        foreach (str_split($raw_attribute) as $char) {
            if($escaped) {
                // a quoted-pair: keep the escaped character
                $value .= $char;
                $escaped = false;
                continue;
            }
            if($inside_word) {
                if($char === '\\') {
                    $escaped = true;
                }elseif($char === "\"" && $value !== "") {
                    $inside_word = false;
                }else{
                    $value .= $char;
                }
            }else{
                if($inside_key) {
                    if($char === '"') {
                        $inside_word = true;
                    }elseif($char === ';'){
                        $attributes[$key] = $value;
                        $key = '';
                        $value = '';
                        $inside_key = true;
                    }elseif($char === '=') {
                        $inside_key = false;
                    }else{
                        $key .= $char;
                    }
                }else{
                    if($char === '"' && $value === "") {
                        $inside_word = true;
                    }elseif($char === ';'){
                        $attributes[$key] = $value;
                        $key = '';
                        $value = '';
                        $inside_key = true;
                    }else{
                        $value .= $char;
                    }
                }
            }
        }
        $attributes[$key] = $value;
        $result = [];

        foreach($attributes as $key => $value) {
            if (($pos = strpos($key, "*")) !== false) {
                $key = substr($key, 0, $pos);
            }
            $key = trim(rtrim(strtolower($key)));

            if(!isset($result[$key])) {
                $result[$key] = "";
            }
            $value = trim(rtrim(str_replace(["\r", "\n"], "", $value)));
            if(str_starts_with($value, "\"") && str_ends_with($value, "\"")) {
                $value = substr($value, 1, -1);
            }
            $result[$key] .= $value;
        }
        return $result;
    }

    /**
     * Exception handling for invalid dates
     *
     * Known bad and "invalid" formats:
     * ^ Datetime                                   ^ Problem                           ^ Cause
     * | Mon, 20 Nov 2017 20:31:31 +0800 (GMT+8:00) | Double timezone specification     | A Windows feature
     * | Thu, 8 Nov 2018 08:54:58 -0200 (-02)       |
     * |                                            | and invalid timezone (max 6 char) |
     * | 04 Jan 2018 10:12:47 UT                    | Missing letter "C"                | Unknown
     * | Thu, 31 May 2018 18:15:00 +0800 (added by) | Non-standard details added by the | Unknown
     * |                                            | mail server                       |
     * | Sat, 31 Aug 2013 20:08:23 +0580            | Invalid timezone                  | PHPMailer bug https://sourceforge.net/p/phpmailer/mailman/message/6132703/
     * | Mi., 23 Apr. 2025 09:48:37 +0200 (MESZ)    | Non-standard localized format     | Aqua Mail S/MIME implementation
     *
     * Please report any new invalid timestamps to [#45](https://github.com/Webklex/php-imap/issues)
     *
     * @param object $header
     *
     * @throws InvalidMessageDateException
     */
    private function parseDate(object $header): void {

        if (property_exists($header, 'date')) {
            $date = $header->date;

            // Cap the length before the regex fallbacks below run against this
            // attacker-controlled value, to avoid catastrophic backtracking (ReDoS).
            if (is_string($date) && strlen($date) > 255) {
                $date = substr($date, 0, 255);
            }

            if (preg_match('/\+0580/', $date)) {
                $date = str_replace('+0580', '+0530', $date);
            }

            $date = trim(rtrim($date));
            try {
                if (str_contains($date, '&nbsp;')) {
                    $date = str_replace('&nbsp;', ' ', $date);
                }
                if (str_contains($date, ' UT ')) {
                    $date = str_replace(' UT ', ' UTC ', $date);
                }
                $parsed_date = Carbon::parse($date);
            } catch (\Exception $e) {
                switch (true) {
                    case preg_match('/^(.+)\s+\1$/', $date, $matches) > 0:
                        $date = $matches[1];
                        break;
                    case preg_match('/([0-9]{4}\.[0-9]{1,2}\.[0-9]{1,2}\-[0-9]{1,2}\.[0-9]{1,2}.[0-9]{1,2})+$/i', $date) > 0:
                        $date = Carbon::createFromFormat("Y.m.d-H.i.s", $date);
                        break;
                    case preg_match('/([0-9]{2} [A-Z]{3} [0-9]{4} [0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2} [+-][0-9]{1,4} [0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2} [+-][0-9]{1,4})+$/i', $date) > 0:
                        $parts = explode(' ', $date);
                        array_splice($parts, -2);
                        $date = implode(' ', $parts);
                        break;
                    case preg_match('/([A-Z]{2,4}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})+$/i', $date) > 0:
                        $array = explode(',', $date);
                        array_shift($array);
                        $date = Carbon::createFromFormat("d M Y H:i:s O", trim(implode(',', $array)));
                        break;
                    case preg_match('/([0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ UT)+$/i', $date) > 0:
                    case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ ([0-9]{2}|[0-9]{4})\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ UT)+$/i', $date) > 0:
                        $date .= 'C';
                        break;
                    case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}[\,]\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})+$/i', $date) > 0:
                        $date = str_replace(',', '', $date);
                        break;
                    // match case for: Di., 15 Feb. 2022 06:52:44 +0100 (MEZ)/Di., 15 Feb. 2022 06:52:44 +0100 (MEZ) and Mi., 23 Apr. 2025 09:48:37 +0200 (MESZ)
                    case preg_match('/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \([A-Z]{3,4}\))(\/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \([A-Z]{3,4}\))+)?$/i', $date) > 0:
                        $dates = explode('/', $date);
                        $date = array_shift($dates);
                        $array = explode(',', $date);
                        array_shift($array);
                        $date = trim(implode(',', $array));
                        $array = explode(' ', $date);
                        array_pop($array);
                        $date = trim(implode(' ', $array));
                        $date = Carbon::createFromFormat("d M. Y H:i:s O", $date);
                        break;
                    // match case for: fr., 25 nov. 2022 06:27:14 +0100/fr., 25 nov. 2022 06:27:14 +0100
                    case preg_match('/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})\/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})+$/i', $date) > 0:
                        $dates = explode('/', $date);
                        $date = array_shift($dates);
                        $array = explode(',', $date);
                        array_shift($array);
                        $date = trim(implode(',', $array));
                        $date = Carbon::createFromFormat("d M. Y H:i:s O", $date);
                        break;
                    case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ \+[0-9]{2,4}\ \(\+[0-9]{1,2}\))+$/i', $date) > 0:
                    case preg_match('/([A-Z]{2,3}[\,|\ \,]\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}.*)+$/i', $date) > 0:
                    case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \(.*)\)+$/i', $date) > 0:
                    case preg_match('/([A-Z]{2,3}\, \ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \(.*)\)+$/i', $date) > 0:
                    case preg_match('/([0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{2,4}\ [0-9]{2}\:[0-9]{2}\:[0-9]{2}\ [A-Z]{2}\ \-[0-9]{2}\:[0-9]{2}\ \([A-Z]{2,3}\ \-[0-9]{2}:[0-9]{2}\))+$/i', $date) > 0:
                        $array = explode('(', $date);
                        $array = array_reverse($array);
                        $date = trim(array_pop($array));
                        break;
                }
                try {
                    $parsed_date = Carbon::parse($date);
                } catch (\Exception $_e) {
                    if (!isset($this->options["fallback_date"])) {
                        throw new InvalidMessageDateException("Invalid message date. ID:" . $this->get("message_id") . " Date:" . $header->date . "/" . $date, 1100, $e);
                    } else {
                        $parsed_date = Carbon::parse($this->options["fallback_date"]);
                    }
                }
            }

            $this->set("date", $parsed_date);
        }
    }

    /**
     * Get all available attributes
     *
     * @return array
     */
    public function getAttributes(): array {
        return $this->attributes;
    }

    /**
     * Set all header attributes
     * @param array $attributes
     *
     * @return Header
     */
    public function setAttributes(array $attributes): Header {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Set the configuration used for parsing a raw header
     * @param array $config
     *
     * @return Header
     */
    public function setOptions(array $config): Header {
        $this->options = $config;
        return $this;
    }

    /**
     * Get the configuration used for parsing a raw header
     *
     * @return array
     */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * Set the configuration used for parsing a raw header
     * @param Config $config
     *
     * @return Header
     */
    public function setConfig(Config $config): Header {
        $this->config = $config;
        return $this;
    }

    /**
     * Get the configuration used for parsing a raw header
     *
     * @return Config
     */
    public function getConfig(): Config {
        return $this->config;
    }

    /**
     * Get the decoder instance
     *
     * @return DecoderInterface
     */
    public function getDecoder(): DecoderInterface {
        return $this->decoder;
    }

    /**
     * Set the decoder instance
     * @param DecoderInterface $decoder
     *
     * @return $this
     */
    public function setDecoder(DecoderInterface $decoder): static {
        $this->decoder = $decoder;
        return $this;
    }

    /**
     * Detect spoofing by checking the from, reply_to, return_path, sender and envelope_from headers
     * @throws SpoofingAttemptDetectedException
     */
    private function detectSpoofing(): void {
        $header_keys = ["from", "reply_to", "return_path", "sender", "envelope_from"];
        $potential_senders = [];
        foreach($header_keys as $key) {
            $header = $this->get($key);
            foreach ($header->toArray() as $address) {
                // the null sender ("Return-Path: <>", e.g. bounces) is no other sender
                if ($address->mail === "") {
                    continue;
                }
                $potential_senders[] = strtolower($address->mail);
            }
        }
        // Deduplicate: the same address appearing in e.g. From and Return-Path is the
        // normal case for delivered mail and must not be treated as spoofing.
        $potential_senders = array_unique($potential_senders);
        if(count($potential_senders) > 1) {
            $this->set("spoofed", true);
            if($this->config->get('security.detect_spoofing_exception', false)) {
                throw new SpoofingAttemptDetectedException("Potential spoofing detected. Message ID: " . $this->get("message_id") . " Senders: " . implode(", ", $potential_senders));
            }
        }
    }

}
