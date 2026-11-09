<?php
/*
* File: Structure.php
* Category: -
* Author: M.Goldenbaum
* Created: 17.09.20 20:38
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;


use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;

/**
 * Class Structure
 *
 * @package Webklex\PHPIMAP
 */
class Structure {

    /**
     * Raw structure
     *
     * @var string $raw
     */
    public string $raw = "";

    /**
     * @var Header $header
     */
    private Header $header;

    /**
     * Message type (if multipart or not)
     *
     * @var int $type
     */
    public int $type = IMAP::MESSAGE_TYPE_TEXT;

    /**
     * All available parts
     *
     * @var Part[] $parts
     */
    public array $parts = [];

    /**
     * Options holder
     *
     * @var array $options
     */
    protected array $options = [];

    /**
     * Structure constructor.
     * @param $raw_structure
     * @param Header $header
     *
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     */
    public function __construct($raw_structure, Header $header) {
        $this->raw = $raw_structure;
        $this->header = $header;
        $this->options = $header->getConfig()->get('options');
        $this->parse();
    }

    /**
     * Parse the given raw structure
     *
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     */
    protected function parse(): void {
        $this->findContentType();
        $this->parts = $this->find_parts();
    }

    /**
     * Determine the message content type
     */
    public function findContentType(): void {
        $content_type = $this->header->get("content_type")->first();
        if($content_type && stripos($content_type, 'multipart') === 0) {
            $this->type = IMAP::MESSAGE_TYPE_MULTIPART;
        }else{
            $this->type = IMAP::MESSAGE_TYPE_TEXT;
        }
    }

    /**
     * Find all available headers and return the leftover body segment
     *
     * The context is taken by reference and emptied as soon as it is split into header and body: a nested
     * multipart message would otherwise keep a copy of the remaining message on every level of the recursion,
     * so the memory grew with nesting depth times message size.
     * @var string $context
     * @var integer $part_number
     *
     * @return Part[]
     * @throws InvalidMessageDateException
     */
    private function parsePart(string &$context, int $part_number = 0, int $depth = 0): array {
        [$headers, $body] = Part::splitHeaderAndBody($context);
        $context = "";

        $config = $this->header->getConfig();
        $headers = new Header($headers, $config);
        if (($boundary = $headers->getBoundary()) !== null) {
            // we return these parts if there are multiple or if configured to allow a single part here (default)
            // isolated cases a single part here causes an empty body, to workaround this set
            // allow_single_parts to false in your config
            if ($config->get("options.allow_single_parts", true)) {
                return $this->detectParts($boundary, $body, $part_number, $depth + 1);
            }
            // the body is still needed if there is only one part: hand over a second reference instead
            $multipart_body = $body;
            $parts = $this->detectParts($boundary, $multipart_body, $part_number, $depth + 1);
            if (count($parts) > 1) {
                return $parts;
            }
        }

        return [new Part($body, $this->header->getConfig(), $headers, $part_number)];
    }

    /**
     * Split a multipart body and parse its body parts
     *
     * The context is taken by reference and emptied once it is split, and every body part is released as soon
     * as it is parsed (see parsePart()).
     * @param string $boundary
     * @param string $context
     * @param int $part_number
     *
     * @return array
     * @throws InvalidMessageDateException
     */
    private function detectParts(string $boundary, string &$context, int $part_number = 0, int $depth = 0): array {
        // Bound the nesting depth of multipart structures to prevent a crafted,
        // deeply nested message from exhausting the stack/memory.
        $max_depth = (int)($this->options["max_mime_depth"] ?? 50);
        if ($depth > $max_depth) {
            return [new Part($context, $this->header->getConfig(), $this->header, $part_number)];
        }
        $contexts = self::splitMultipart($boundary, $context);
        $context = "";

        $final_parts = [];
        $count = count($contexts);
        for ($i = 0; $i < $count; $i++) {
            if (trim($contexts[$i]) !== "") {
                $parts = $this->parsePart($contexts[$i], $part_number, $depth);
                foreach ($parts as $part) {
                    $final_parts[] = $part;
                    $part_number = $part->part_number;
                }
                $part_number++;
            }
            unset($contexts[$i]);
        }
        return $final_parts;
    }

    /**
     * Split a multipart body into its body parts (RFC 2046 5.1.1)
     *
     * A delimiter is "--boundary" at the beginning of a line, optionally followed by whitespace; the line break
     * in front of it belongs to the delimiter. CRLF and bare LF line endings are accepted (some servers return
     * messages as they were stored, e.g. with LF only), the content of the parts is not changed. The preamble
     * and the epilogue after the close delimiter ("--boundary--") are no parts. If the body does not contain
     * the delimiter at all, the whole body is returned as a single part.
     *
     * The delimiters are searched with strpos() instead of a regular expression that contains the boundary:
     * such a pattern fails to compile for a very long boundary ("regular expression is too large"), and the
     * whole multipart body would end up as a single part.
     * @param string $boundary
     * @param string $context
     *
     * @return string[]
     */
    public static function splitMultipart(string $boundary, string $context): array {
        $delimiters = self::findDelimiters("--" . $boundary, $context);
        if (count($delimiters) === 0) {
            return [$context];
        }

        $parts = [];
        $count = count($delimiters);
        for ($i = 0; $i < $count; $i++) {
            [, $delimiter_end, $close] = $delimiters[$i];
            if ($close) {
                // close delimiter - everything behind it is the epilogue
                break;
            }
            $end = isset($delimiters[$i + 1]) ? $delimiters[$i + 1][0] : strlen($context);
            $part = substr($context, $delimiter_end, $end - $delimiter_end);
            if (!isset($delimiters[$i + 1])) {
                // the close delimiter is missing (truncated message): the trailing line break is no content
                if (str_ends_with($part, "\r\n")) {
                    $part = substr($part, 0, -2);
                } elseif (str_ends_with($part, "\n")) {
                    $part = substr($part, 0, -1);
                }
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Find the delimiter lines of a multipart body
     *
     * Same matches as the pattern /(?:\A|\r?\n)--boundary(--)?[ \t]*(?=\r?\n|\z)(?:\r?\n)?/ with
     * preg_match_all(): the line break in front of a delimiter and the one behind it belong to the delimiter,
     * matches do not overlap.
     * @param string $delimiter "--" followed by the boundary
     * @param string $context
     *
     * @return array<int, array{0: int, 1: int, 2: bool}> start and end offset of each delimiter, true for the
     *                                                    close delimiter
     */
    private static function findDelimiters(string $delimiter, string $context): array {
        $delimiters = [];
        $length = strlen($context);
        $delimiter_length = strlen($delimiter);
        $offset = 0;
        while (($pos = strpos($context, $delimiter, $offset)) !== false) {
            // the delimiter has to start a line; a line break that belongs to the previous match does not count
            if ($pos === 0 && $offset === 0) {
                $start = 0;
            } elseif ($pos - 1 >= $offset && $context[$pos - 1] === "\n") {
                $start = $pos - 2 >= $offset && $context[$pos - 2] === "\r" ? $pos - 2 : $pos - 1;
            } else {
                $offset = $pos + 1;
                continue;
            }

            $after = $pos + $delimiter_length;
            $close = substr($context, $after, 2) === "--";
            $end = self::delimiterLineEnd($context, $close ? $after + 2 : $after, $length);
            if ($end === null && $close) {
                // "--boundary--x" is no close delimiter, but maybe "--boundary" followed by "--x" neither
                $close = false;
                $end = self::delimiterLineEnd($context, $after, $length);
            }
            if ($end === null) {
                $offset = $pos + 1;
                continue;
            }

            $delimiters[] = [$start, $end, $close];
            $offset = $end;
        }

        return $delimiters;
    }

    /**
     * End of a delimiter line: optional whitespace, then a line break (included) or the end of the body
     * @param string $context
     * @param int $pos position behind the delimiter
     * @param int $length length of the context
     *
     * @return int|null offset behind the delimiter line or null if the line goes on
     */
    private static function delimiterLineEnd(string $context, int $pos, int $length): ?int {
        while ($pos < $length && ($context[$pos] === " " || $context[$pos] === "\t")) {
            $pos++;
        }
        if ($pos === $length) {
            return $pos;
        }
        if ($context[$pos] === "\n") {
            return $pos + 1;
        }
        if ($context[$pos] === "\r" && $pos + 1 < $length && $context[$pos + 1] === "\n") {
            return $pos + 2;
        }
        return null;
    }

    /**
     * Header for the body of a multipart message without boundary: text/plain with the charset and the
     * transfer encoding of the message
     *
     * @return Header
     * @throws InvalidMessageDateException
     */
    private function plainTextHeader(): Header {
        $charset = $this->header->get("charset")->first();
        $encoding = $this->header->get("content_transfer_encoding")->first();
        $raw = "Content-Type: text/plain" . (is_string($charset) && $charset !== "" ? "; charset=\"" . addcslashes($charset, '"\\') . "\"" : "") . "\r\n";
        if (is_string($encoding) && $encoding !== "") {
            $raw .= "Content-Transfer-Encoding: " . trim($encoding) . "\r\n";
        }
        return new Header($raw, $this->header->getConfig());
    }

    /**
     * Find all available parts
     *
     * @return array
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     */
    public function find_parts(): array {
        if($this->type === IMAP::MESSAGE_TYPE_MULTIPART) {
            if (($boundary = $this->header->getBoundary()) === null)  {
                // A multipart without (usable) boundary cannot be split. Like an unrecognized subtype
                // (RFC 2046 5.1.7) its body is taken as plain text instead of rejecting the whole message.
                return [new Part($this->raw, $this->header->getConfig(), $this->plainTextHeader(), 0)];
            }

            $raw = $this->raw;
            return $this->detectParts($boundary, $raw, 0, 0);
        }

        return [new Part($this->raw, $this->header->getConfig(), $this->header)];
    }
}
