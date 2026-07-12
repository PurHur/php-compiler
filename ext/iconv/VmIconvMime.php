<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * iconv MIME + encoding helpers (php-src ext/iconv/iconv.c; #6364).
 */
final class VmIconvMime
{
    public static function mimeDecode(
        string $encoded,
        int $mode,
        ?string $charset,
        ?Frame $frame = null
    ): string|false {
        $outputCharset = null !== $charset && '' !== $charset
            ? $charset
            : IconvEncodingState::getInternalEncoding();
        if (\strlen($outputCharset) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
            self::triggerEncodingLengthWarning($frame);

            return false;
        }
        if (null === CharsetEngine::parseEncodingSpec($outputCharset)) {
            self::triggerWrongEncodingWarning($frame, '???', $outputCharset);

            return false;
        }

        $strict = 0 !== ($mode & IconvConstants::MIME_DECODE_STRICT);
        $continue = 0 !== ($mode & IconvConstants::MIME_DECODE_CONTINUE_ON_ERROR);
        $len = \strlen($encoded);
        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('=' === $encoded[$i] && ($i + 1) < $len && '?' === $encoded[$i + 1]) {
                $parsed = self::parseEncodedWord($encoded, $i, $len);
                if (null === $parsed) {
                    if ($strict && !$continue) {
                        return false;
                    }
                    if ($continue) {
                        $end = self::skipEncodedWordLiteral($encoded, $i, $len);
                        $out .= \substr($encoded, $i, $end - $i);
                        $i = $end;
                        continue;
                    }
                    $out .= $encoded[$i];
                    ++$i;
                    continue;
                }
                [$wordCharset, $payload, $next, $isQ, $invalidScheme] = $parsed;
                if ($invalidScheme) {
                    if ($strict && !$continue) {
                        return false;
                    }
                    if ($continue) {
                        $out .= \substr($encoded, $i, $next - $i);
                        $i = $next;
                        continue;
                    }
                }
                $decodedBytes = $isQ ? self::qDecode($payload) : self::base64Decode($payload);
                $converted = self::convertWord($wordCharset, $outputCharset, $decodedBytes, $frame);
                if (false === $converted) {
                    if ($continue) {
                        $out .= \substr($encoded, $i, $next - $i);
                        $i = $next;
                        continue;
                    }

                    return false;
                }
                $out .= $converted;
                $i = $next;
                while ($i < $len && self::isWhitespace($encoded[$i])) {
                    ++$i;
                }
                if ($i < $len && '=' === $encoded[$i] && ($i + 1) < $len && '?' === $encoded[$i + 1]) {
                    continue;
                }
                if ($i < $len && !$strict) {
                    $out .= ' ';
                }
                continue;
            }

            $start = $i;
            while ($i < $len) {
                if ('=' === $encoded[$i] && ($i + 1) < $len && '?' === $encoded[$i + 1]) {
                    break;
                }
                if ("\n" === $encoded[$i] || "\r" === $encoded[$i]) {
                    ++$i;
                    while ($i < $len && self::isWhitespace($encoded[$i])) {
                        ++$i;
                    }
                    if ($i < $len && !$strict) {
                        $out .= ' ';
                    }
                    break;
                }
                ++$i;
            }
            if ($i > $start) {
                $out .= \substr($encoded, $start, $i - $start);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public static function mimeEncode(
        string $fieldName,
        string $fieldValue,
        ?array $preferences,
        ?Frame $frame = null
    ): string|false {
        $inCharset = IconvEncodingState::getInternalEncoding();
        $outCharset = $inCharset;
        $scheme = 'B';
        if (null !== $preferences) {
            if (isset($preferences['scheme']) && \is_string($preferences['scheme']) && '' !== $preferences['scheme']) {
                $scheme = $preferences['scheme'][0];
            }
            if (isset($preferences['input-charset']) && \is_string($preferences['input-charset'])) {
                if (\strlen($preferences['input-charset']) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
                    self::triggerEncodingLengthWarning($frame, 'iconv_mime_encode');

                    return false;
                }
                if ('' !== $preferences['input-charset']) {
                    $inCharset = $preferences['input-charset'];
                }
            }
            if (isset($preferences['output-charset']) && \is_string($preferences['output-charset'])) {
                if (\strlen($preferences['output-charset']) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
                    self::triggerEncodingLengthWarning($frame, 'iconv_mime_encode');

                    return false;
                }
                if ('' !== $preferences['output-charset']) {
                    $outCharset = $preferences['output-charset'];
                }
            }
        }
        if (null === CharsetEngine::parseEncodingSpec($inCharset)
            || null === CharsetEngine::parseEncodingSpec($outCharset)) {
            self::triggerWrongEncodingWarning($frame, $outCharset, $inCharset, 'iconv_mime_encode');

            return false;
        }
        $converted = CharsetEngine::convert($inCharset, $outCharset, $fieldValue);
        if (false === $converted) {
            self::triggerWrongEncodingWarning($frame, $outCharset, $inCharset, 'iconv_mime_encode');

            return false;
        }
        $useQ = 'Q' === $scheme || 'q' === $scheme;
        $word = $useQ
            ? '=?'.$outCharset.'?Q?'.self::qEncode($converted).'?='
            : '=?'.$outCharset.'?B?'.\base64_encode($converted).'?=';

        return $fieldName.': '.$word;
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: bool, 4: bool}|null
     */
    private static function parseEncodedWord(string $str, int $pos, int $len): ?array
    {
        if (($pos + 5) >= $len || '=' !== $str[$pos] || '?' !== $str[$pos + 1]) {
            return null;
        }
        $charsetEnd = \strpos($str, '?', $pos + 2);
        if (false === $charsetEnd || ($charsetEnd + 2) >= $len) {
            return null;
        }
        $wordCharset = \substr($str, $pos + 2, $charsetEnd - ($pos + 2));
        if ('' === $wordCharset || \strlen($wordCharset) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
            return null;
        }
        $encoding = $str[$charsetEnd + 1];
        if ('?' !== $str[$charsetEnd + 2]) {
            return null;
        }
        $invalidScheme = 'B' !== $encoding && 'b' !== $encoding && 'Q' !== $encoding && 'q' !== $encoding;
        $isQ = 'Q' === $encoding || 'q' === $encoding;
        $dataStart = $charsetEnd + 3;
        $dataEnd = \strpos($str, '?=', $dataStart);
        if (false === $dataEnd) {
            if ($len > $dataStart && '?' === $str[$len - 1]) {
                $dataEnd = $len - 1;
                $next = $len;
            } else {
                return null;
            }
        } else {
            $next = $dataEnd + 2;
        }
        $payload = \substr($str, $dataStart, $dataEnd - $dataStart);

        return [$wordCharset, $payload, $next, $isQ, $invalidScheme];
    }

    private static function skipEncodedWordLiteral(string $str, int $pos, int $len): int
    {
        $qmarks = 2;
        $i = $pos + 2;
        while ($i < $len && $qmarks > 0) {
            if ('?' === $str[$i]) {
                --$qmarks;
            }
            ++$i;
        }
        if ($i < $len && '=' === $str[$i]) {
            ++$i;
        }

        return $i;
    }

    private static function convertWord(
        string $fromCharset,
        string $toCharset,
        string $bytes,
        ?Frame $frame
    ): string|false {
        if (null === CharsetEngine::parseEncodingSpec($fromCharset)) {
            return false;
        }
        if ($fromCharset === $toCharset || self::normalizeCharset($fromCharset) === self::normalizeCharset($toCharset)) {
            return $bytes;
        }
        $converted = CharsetEngine::convert($fromCharset, $toCharset, $bytes);
        if (false === $converted) {
            self::triggerWrongEncodingWarning($frame, $toCharset, $fromCharset);

            return false;
        }

        return $converted;
    }

    private static function normalizeCharset(string $charset): string
    {
        return strtoupper(str_replace(['-', '_'], '', $charset));
    }

    private static function base64Decode(string $payload): string
    {
        $clean = \preg_replace('/[\r\n\t =]/', '', $payload);
        if (!\is_string($clean) || '' === $clean) {
            return '';
        }
        $decoded = \base64_decode($clean, true);

        return false === $decoded ? '' : $decoded;
    }

    private static function qDecode(string $payload): string
    {
        $out = '';
        $len = \strlen($payload);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $payload[$i];
            if ('_' === $byte) {
                $out .= ' ';
                continue;
            }
            if ('=' === $byte && ($i + 2) < $len) {
                $hex = \hexdec(\substr($payload, $i + 1, 2));
                $out .= \chr((int) $hex);
                $i += 2;
                continue;
            }
            $out .= $byte;
        }

        return $out;
    }

    private static function qEncode(string $text): string
    {
        $out = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $text[$i];
            $ord = \ord($byte);
            if ($ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord) {
                $out .= $byte;
                continue;
            }
            if (0x20 === $ord) {
                $out .= '_';
                continue;
            }
            $out .= \sprintf('=%02X', $ord);
        }

        return $out;
    }

    private static function isWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\r" === $byte || "\n" === $byte;
    }

    private static function triggerEncodingLengthWarning(?Frame $frame, string $function = 'iconv_mime_decode'): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf(
                '%s(): Encoding parameter exceeds the maximum allowed length of %d characters',
                $function,
                IconvConstants::ENCODING_NAME_MAX_LEN
            ),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function triggerWrongEncodingWarning(
        ?Frame $frame,
        string $to,
        string $from,
        string $function = 'iconv_mime_decode'
    ): void {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf(
                '%s(): Wrong encoding, conversion from "%s" to "%s" is not allowed',
                $function,
                $from,
                $to
            ),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
