<?php
/*
* File: Decoder.php
* Category: -
* Author: M.Goldenbaum
* Created: 12.04.24 20:14
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Decoder;

use Webklex\PHPIMAP\EncodingAliases;

/**
 * Class Decoder
 *
 * @package Webklex\PHPIMAP
 */
abstract class Decoder implements DecoderInterface {

    /**
     * Decoder constructor.
     * @param array $options Decoder options
     * @param string $fallback_encoding Fallback encoding
     */
    public function __construct(
        /**
         * Options
         *
         * @var array
         */
        protected array  $options = [],

        /**
         * Fallback Encoding
         *
         * @var string
         */
        protected string $fallback_encoding = 'UTF-8',
    ) {
        $this->options = array_merge([
            'header' => 'utf-8',
            'message' => 'utf-8',
            'attachment' => 'utf-8',
        ], $this->options);
    }

    /**
     * Decode a given value
     * @param array|string|null $value
     * @param string|null $encoding
     * @return mixed
     */
    public function decode(array|string|null $value, ?string $encoding = null): mixed {
        return $value;
    }

    /**
     * Convert the encoding
     * @param string $str The string to convert
     * @param string $from The source encoding
     * @param string $to The target encoding
     *
     * @return mixed|string
     */
    public function convertEncoding(string $str, string $from = "ISO-8859-2", string $to = "UTF-8"): mixed {
        $from = EncodingAliases::get($from, $this->fallback_encoding);
        $to = EncodingAliases::get($to, $this->fallback_encoding);

        if ($from === $to) {
            return $str;
        }

        return EncodingAliases::convert($str, $from, $to);
    }

    /**
     * Decode MIME header elements
     * @link https://php.net/manual/en/function.imap-mime-header-decode.php
     * @param string $text The MIME text
     *
     * @return array<int,object> Returns an array of objects. Each *object has two properties, charset and text.
     */
    public function mimeHeaderDecode(string $text): array {
        if (extension_loaded('imap')) {
            $result = \imap_mime_header_decode($text);
            return is_array($result) ? $result : [];
        }
        return self::decodeMimeHeader($text);
    }

    /**
     * Pure PHP implementation of imap_mime_header_decode() (ext-imap is unbundled since PHP 8.4)
     *
     * Splits the text into encoded-words (RFC 2047) and plain text. Every encoded-word becomes an object with
     * its charset and the decoded, not yet converted bytes; plain text gets the charset "default". Whitespace
     * between two adjacent encoded-words is dropped. The conversion into UTF-8 is left to the caller (via
     * EncodingAliases), so charsets iconv does not know by name (e.g. ks_c_5601-1987) work as well.
     * Unlike c-client, an encoded-word with an unknown encoding or invalid base64 is kept as plain text and
     * an RFC 2231 language suffix ("utf-8*de") is removed from the charset.
     * @param string $text
     *
     * @return array<int,object>
     */
    public static function decodeMimeHeader(string $text): array {
        $result = [];
        $plain = "";
        $last_was_word = false;
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            if (!preg_match('/=\?([^?\s]+)\?([bq])\?([^?]*)\?=/i', $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $start = $match[0][1];
            $decoded = self::decodeEncodedWord($match[2][0], $match[3][0]);
            if ($decoded === null) {
                // not decodable - keep it as plain text and continue behind its "=?"
                $plain .= substr($text, $offset, $start - $offset + 2);
                $offset = $start + 2;
                $last_was_word = false;
                continue;
            }

            $between = substr($text, $offset, $start - $offset);
            if (!($last_was_word && $plain === "" && trim($between, " \t\r\n") === "")) {
                $plain .= $between;
            }
            if ($plain !== "") {
                $result[] = (object)["charset" => "default", "text" => $plain];
                $plain = "";
            }

            $charset = $match[1][0];
            if (($pos = strpos($charset, "*")) !== false) {
                $charset = substr($charset, 0, $pos);
            }
            $result[] = (object)["charset" => $charset, "text" => $decoded];
            $offset = $start + strlen($match[0][0]);
            $last_was_word = true;
        }

        $plain .= substr($text, $offset);
        if ($plain !== "") {
            $result[] = (object)["charset" => "default", "text" => $plain];
        }

        return $result;
    }

    /**
     * Decode the text of a single encoded-word
     * @param string $encoding "B" or "Q"
     * @param string $text
     *
     * @return string|null The decoded bytes or null if the text is not valid for the encoding
     */
    private static function decodeEncodedWord(string $encoding, string $text): ?string {
        if (strtoupper($encoding) === "B") {
            $decoded = base64_decode($text, true);
            return $decoded === false ? null : $decoded;
        }

        return quoted_printable_decode(str_replace("_", " ", $text));
    }

    /**
     * Test if a given value is utf-8 encoded
     * @param $value
     *
     * @return bool
     */
    public static function isUTF8($value): bool {
        return str_starts_with(strtolower($value), '=?utf-8?');
    }

    /**
     * Check if a given pair of strings has been decoded
     * @param $encoded
     * @param $decoded
     *
     * @return bool
     */
    public static function notDecoded($encoded, $decoded): bool {
        return str_starts_with($decoded, '=?')
            && strlen($decoded) - 2 === strpos($decoded, '?=')
            && str_contains($encoded, $decoded);
    }

    /**
     * Set the configuration used for decoding
     * @param array $config
     *
     * @return Decoder
     */
    public function setOptions(array $config): static {
        $this->options = $config;
        return $this;
    }

    /**
     * Get the configuration used for decoding
     *
     * @return array
     */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * Get the fallback encoding
     *
     * @return string
     */
    public function getFallbackEncoding(): string {
        return $this->fallback_encoding;
    }

    /**
     * Set the fallback encoding
     *
     * @param string $fallback_encoding
     * @return Decoder
     */
    public function setFallbackEncoding(string $fallback_encoding): static {
        $this->fallback_encoding = $fallback_encoding;
        return $this;
    }
}