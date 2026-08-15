<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Process-global iconv encoding settings (php-src ext/iconv/iconv.c ini entries; #6364).
 */
final class IconvEncodingState
{
    private static string $inputEncoding = 'UTF-8';
    private static string $outputEncoding = 'UTF-8';
    private static string $internalEncoding = 'UTF-8';

    public static function getInputEncoding(): string
    {
        return self::$inputEncoding;
    }

    public static function getOutputEncoding(): string
    {
        return self::$outputEncoding;
    }

    public static function getInternalEncoding(): string
    {
        return self::$internalEncoding;
    }

    /**
     * php-src: omitted $type defaults to "all"; empty string (incl. soft-null coerce) is invalid → false (#31311).
     *
     * @return array{input_encoding: string, output_encoding: string, internal_encoding: string}|string|false
     */
    public static function getEncoding(?string $type): array|string|false
    {
        // null sentinel = omitted arg (iconv_get_encoding() with argc 0), not soft-null "".
        if (null === $type) {
            return [
                'input_encoding' => self::$inputEncoding,
                'output_encoding' => self::$outputEncoding,
                'internal_encoding' => self::$internalEncoding,
            ];
        }
        $normalized = strtolower($type);
        if ('all' === $normalized) {
            return [
                'input_encoding' => self::$inputEncoding,
                'output_encoding' => self::$outputEncoding,
                'internal_encoding' => self::$internalEncoding,
            ];
        }
        if ('input_encoding' === $normalized) {
            return self::$inputEncoding;
        }
        if ('output_encoding' === $normalized) {
            return self::$outputEncoding;
        }
        if ('internal_encoding' === $normalized) {
            return self::$internalEncoding;
        }

        return false;
    }

    public static function setEncoding(string $type, string $charset): bool
    {
        if (\strlen($charset) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
            return false;
        }
        $normalized = strtolower($type);
        if ('input_encoding' === $normalized) {
            self::$inputEncoding = $charset;

            return true;
        }
        if ('output_encoding' === $normalized) {
            self::$outputEncoding = $charset;

            return true;
        }
        if ('internal_encoding' === $normalized) {
            self::$internalEncoding = $charset;

            return true;
        }

        return false;
    }

    /**
     * @param array{input_encoding: string, output_encoding: string, internal_encoding: string} $data
     */
    public static function encodingArrayToHashTable(array $data): HashTable
    {
        $ht = new HashTable();
        foreach ($data as $key => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add($key, $slot);
        }

        return $ht;
    }
}
