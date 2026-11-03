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
     * @var string $context
     * @var integer $part_number
     *
     * @return Part[]
     * @throws InvalidMessageDateException
     */
    private function parsePart(string $context, int $part_number = 0, int $depth = 0): array {
        [$headers, $body] = Part::splitHeaderAndBody($context);

        $config = $this->header->getConfig();
        $headers = new Header($headers, $config);
        if (($boundary = $headers->getBoundary()) !== null) {
            $parts = $this->detectParts($boundary, $body, $part_number, $depth + 1);

            // we return these parts if there are multiple or if configured to allow a single part here (default)
            // isolated cases a single part here causes an empty body, to workaround this set
            // allow_single_parts to false in your config
            if (count($parts) > 1 || $config->get("options.allow_single_parts", true)) {
                return $parts;
            }
        }

        return [new Part($body, $this->header->getConfig(), $headers, $part_number)];
    }

    /**
     * @param string $boundary
     * @param string $context
     * @param int $part_number
     *
     * @return array
     * @throws InvalidMessageDateException
     */
    private function detectParts(string $boundary, string $context, int $part_number = 0, int $depth = 0): array {
        // Bound the nesting depth of multipart structures to prevent a crafted,
        // deeply nested message from exhausting the stack/memory.
        $max_depth = (int)($this->options["max_mime_depth"] ?? 50);
        if ($depth > $max_depth) {
            return [new Part($context, $this->header->getConfig(), $this->header, $part_number)];
        }
        $final_parts = [];
        foreach(self::splitMultipart($boundary, $context) as $ctx) {
            if (trim($ctx) !== "") {
                $parts = $this->parsePart($ctx, $part_number, $depth);
                foreach ($parts as $part) {
                    $final_parts[] = $part;
                    $part_number = $part->part_number;
                }
                $part_number++;
            }
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
     * @param string $boundary
     * @param string $context
     *
     * @return string[]
     */
    public static function splitMultipart(string $boundary, string $context): array {
        $pattern = '/(?:\A|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?=\r?\n|\z)(?:\r?\n)?/';
        if (!preg_match_all($pattern, $context, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [$context];
        }

        $parts = [];
        $count = count($matches);
        for ($i = 0; $i < $count; $i++) {
            if (isset($matches[$i][1]) && $matches[$i][1][0] === "--") {
                // close delimiter - everything behind it is the epilogue
                break;
            }
            $start = $matches[$i][0][1] + strlen($matches[$i][0][0]);
            $end = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : strlen($context);
            $part = substr($context, $start, $end - $start);
            if (!isset($matches[$i + 1])) {
                // the close delimiter is missing (truncated message): the trailing line break is no content
                $part = preg_replace('/\r?\n\z/', '', $part);
            }
            $parts[] = $part;
        }

        return $parts;
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

            return $this->detectParts($boundary, $this->raw, 0, 0);
        }

        return [new Part($this->raw, $this->header->getConfig(), $this->header)];
    }
}
