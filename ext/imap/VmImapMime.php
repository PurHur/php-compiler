<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Pure-PHP IMAP MIME transfer codecs (php-src ext/imap/php_imap.c; #27683).
 *
 * Maps c-client rfc822_{base64,qprint,8bit,binary} + mime header decode /
 * utf8_mime2text onto existing {@see VmString} primitives — no runtime/*.c.
 */
final class VmImapMime
{
    /**
     * imap_base64() — rfc822_base64; strict alphabet like base64_decode($s, true).
     *
     * @return string|false
     */
    public static function base64(string $string): string|false
    {
        return VmString::base64_decode($string, true);
    }

    /**
     * imap_qprint() — rfc822_qprint (quoted-printable → 8-bit).
     *
     * @return string|false
     */
    public static function qprint(string $string): string|false
    {
        return VmString::quoted_printable_decode($string);
    }

    /**
     * imap_8bit() — rfc822_8bit (8-bit → quoted-printable).
     *
     * @return string|false
     */
    public static function eightBit(string $string): string|false
    {
        return VmString::quoted_printable_encode($string);
    }

    /**
     * imap_binary() — rfc822_binary (8-bit → base64).
     *
     * @return string|false
     */
    public static function binary(string $string): string|false
    {
        return VmString::base64_encode($string);
    }

    /**
     * imap_utf8() — utf8_mime2text approximation (MIME encoded-words → UTF-8).
     */
    public static function utf8(string $mimeEncodedText): string
    {
        $parts = self::mimeHeaderDecodeParts($mimeEncodedText);
        if (false === $parts) {
            return $mimeEncodedText;
        }
        $out = '';
        foreach ($parts as $part) {
            $out .= self::charsetBytesToUtf8($part['charset'], $part['text']);
        }

        return $out;
    }

    /**
     * imap_mime_header_decode() — RFC 2047 fragments to {charset,text} objects.
     *
     * @return list<array{charset: string, text: string}>|false
     */
    public static function mimeHeaderDecodeParts(string $string): array|false
    {
        $end = \strlen($string);
        $offset = 0;
        $out = [];

        while ($offset < $end) {
            $charsetToken = self::memnstr($string, '=?', $offset, $end);
            if (null === $charsetToken) {
                $out[] = [
                    'charset' => 'default',
                    'text' => \substr($string, $offset),
                ];
                break;
            }

            if ($offset !== $charsetToken) {
                $out[] = [
                    'charset' => 'default',
                    'text' => \substr($string, $offset, $charsetToken - $offset),
                ];
            }

            $encodingToken = self::memnstr($string, '?', $charsetToken + 2, $end);
            if (null === $encodingToken) {
                $out[] = [
                    'charset' => 'default',
                    'text' => \substr($string, $charsetToken),
                ];
                break;
            }

            if ($encodingToken + 3 >= $end) {
                $out[] = [
                    'charset' => 'default',
                    'text' => \substr($string, $charsetToken),
                ];
                break;
            }

            $endToken = self::memnstr($string, '?=', $encodingToken + 3, $end);
            if (null === $endToken) {
                $out[] = [
                    'charset' => 'default',
                    'text' => \substr($string, $charsetToken),
                ];
                break;
            }

            $charset = \substr($string, $charsetToken + 2, $encodingToken - ($charsetToken + 2));
            $encoding = $string[$encodingToken + 1];
            $text = \substr($string, $encodingToken + 3, $endToken - ($encodingToken + 3));
            $decode = $text;

            if ('q' === $encoding || 'Q' === $encoding) {
                $q = \str_replace('_', ' ', $text);
                $decode = VmString::quoted_printable_decode($q);
            } elseif ('b' === $encoding || 'B' === $encoding) {
                $decoded = VmString::base64_decode($text, true);
                if (false === $decoded) {
                    return false;
                }
                $decode = $decoded;
            }

            $out[] = [
                'charset' => $charset,
                'text' => $decode,
            ];

            $offset = $endToken + 2;
            $i = 0;
            while (
                $offset + $i < $end
                && (
                    ' ' === $string[$offset + $i]
                    || "\n" === $string[$offset + $i]
                    || "\r" === $string[$offset + $i]
                    || "\t" === $string[$offset + $i]
                )
            ) {
                ++$i;
            }
            if (
                $offset + $i + 1 < $end
                && '=' === $string[$offset + $i]
                && '?' === $string[$offset + $i + 1]
            ) {
                $offset += $i;
            }
        }

        return $out;
    }

    /**
     * @param list<array{charset: string, text: string}> $parts
     */
    public static function mimeHeaderPartsToVariable(array $parts, Context $ctx): Variable
    {
        self::ensureStdClass($ctx);
        $ht = new HashTable();
        foreach ($parts as $part) {
            $obj = new ObjectEntry($ctx->classes['stdclass']);
            $obj->constructed = true;
            $charsetProp = $obj->allocateProperty('charset');
            $charsetProp->string($part['charset']);
            $textProp = $obj->allocateProperty('text');
            $textProp->string($part['text']);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return $var;
    }

    private static function charsetBytesToUtf8(string $charset, string $text): string
    {
        $lc = \strtolower($charset);
        if ('default' === $lc || 'utf-8' === $lc || 'utf8' === $lc || 'us-ascii' === $lc || 'ascii' === $lc) {
            return $text;
        }
        if (
            'iso-8859-1' === $lc
            || 'iso8859-1' === $lc
            || 'latin1' === $lc
            || 'latin-1' === $lc
        ) {
            return self::latin1ToUtf8($text);
        }

        return $text;
    }

    private static function latin1ToUtf8(string $s): string
    {
        $out = '';
        $len = \strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            $c = \ord($s[$i]);
            if ($c < 0x80) {
                $out .= $s[$i];
            } else {
                $out .= \chr(0xC0 | ($c >> 6)).\chr(0x80 | ($c & 0x3F));
            }
        }

        return $out;
    }

    private static function memnstr(string $haystack, string $needle, int $start, int $end): ?int
    {
        $nlen = \strlen($needle);
        if (0 === $nlen || $start >= $end) {
            return null;
        }
        $limit = $end - $nlen;
        for ($i = $start; $i <= $limit; ++$i) {
            if (\substr($haystack, $i, $nlen) === $needle) {
                return $i;
            }
        }

        return null;
    }

    private static function ensureStdClass(Context $ctx): void
    {
        if (!isset($ctx->classes['stdclass'])) {
            $ce = new \PHPCompiler\VM\ClassEntry('stdClass');
            $ce->isInternal = true;
            $ctx->classes['stdclass'] = $ce;
        }
    }
}
