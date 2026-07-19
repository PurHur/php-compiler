<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

/**
 * PHP-in-PHP port of php-src ext/dba/libflatfile/flatfile.c (#4422).
 *
 * On-disk format: for each record, ASCII decimal length + "\\n" + bytes (key),
 * then the same for value. Deletes zero the first byte of the key in place.
 */
final class VmDbaFlatfile
{
    /**
     * @param resource $fp
     */
    public static function fetch($fp, string $key): ?string
    {
        if (!self::findKey($fp, $key)) {
            return null;
        }
        $lenLine = \fgets($fp);
        if (false === $lenLine) {
            return null;
        }
        $len = (int) \trim($lenLine);
        if ($len < 0) {
            return null;
        }
        if (0 === $len) {
            return '';
        }
        $data = \fread($fp, $len);
        if (false === $data) {
            return null;
        }

        return $data;
    }

    /**
     * @param resource $fp
     */
    public static function exists($fp, string $key): bool
    {
        return null !== self::fetch($fp, $key);
    }

    /**
     * @param resource $fp
     *
     * @return bool true on success
     */
    public static function insert($fp, string $key, string $value): bool
    {
        if (self::findKey($fp, $key)) {
            return false;
        }
        \fseek($fp, 0, \SEEK_END);
        self::writeRecord($fp, $key, $value);

        return true;
    }

    /**
     * @param resource $fp
     */
    public static function replace($fp, string $key, string $value): bool
    {
        self::delete($fp, $key);
        \fseek($fp, 0, \SEEK_END);
        self::writeRecord($fp, $key, $value);

        return true;
    }

    /**
     * @param resource $fp
     */
    public static function delete($fp, string $key): bool
    {
        \rewind($fp);
        while (!\feof($fp)) {
            $keyLenLine = \fgets($fp);
            if (false === $keyLenLine) {
                break;
            }
            $keyLen = (int) \trim($keyLenLine);
            if ($keyLen < 0) {
                break;
            }
            $keyPos = \ftell($fp);
            $foundKey = 0 === $keyLen ? '' : \fread($fp, $keyLen);
            if (false === $foundKey) {
                break;
            }
            if (\strlen($foundKey) === \strlen($key) && $foundKey === $key) {
                \fseek($fp, $keyPos, \SEEK_SET);
                \fwrite($fp, "\0");
                \fflush($fp);
                \fseek($fp, 0, \SEEK_END);

                return true;
            }
            $valLenLine = \fgets($fp);
            if (false === $valLenLine) {
                break;
            }
            $valLen = (int) \trim($valLenLine);
            if ($valLen > 0) {
                \fread($fp, $valLen);
            }
        }

        return false;
    }

    /**
     * @param resource $fp
     */
    private static function findKey($fp, string $key): bool
    {
        \rewind($fp);
        while (!\feof($fp)) {
            $keyLenLine = \fgets($fp);
            if (false === $keyLenLine) {
                break;
            }
            $keyLen = (int) \trim($keyLenLine);
            if ($keyLen < 0) {
                break;
            }
            $foundKey = 0 === $keyLen ? '' : \fread($fp, $keyLen);
            if (false === $foundKey) {
                break;
            }
            if (\strlen($foundKey) === \strlen($key) && $foundKey === $key) {
                return true;
            }
            $valLenLine = \fgets($fp);
            if (false === $valLenLine) {
                break;
            }
            $valLen = (int) \trim($valLenLine);
            if ($valLen > 0) {
                \fread($fp, $valLen);
            }
        }

        return false;
    }

    /**
     * @param resource $fp
     */
    private static function writeRecord($fp, string $key, string $value): void
    {
        \fwrite($fp, \strlen($key)."\n");
        \fwrite($fp, $key);
        \fwrite($fp, \strlen($value)."\n");
        \fwrite($fp, $value);
        \fflush($fp);
    }

    /**
     * @param resource $fp
     *
     * @return array{0: ?string, 1: int} key + cursor (byte offset after key / at value-len line)
     */
    public static function firstKey($fp): array
    {
        \rewind($fp);
        while (!\feof($fp)) {
            $keyLenLine = \fgets($fp);
            if (false === $keyLenLine) {
                break;
            }
            $keyLen = (int) \trim($keyLenLine);
            if ($keyLen < 0) {
                break;
            }
            $foundKey = 0 === $keyLen ? '' : \fread($fp, $keyLen);
            if (false === $foundKey) {
                break;
            }
            if ('' !== $foundKey && "\0" !== $foundKey[0]) {
                $cursor = (int) \ftell($fp);

                return [$foundKey, $cursor];
            }
            $valLenLine = \fgets($fp);
            if (false === $valLenLine) {
                break;
            }
            $valLen = (int) \trim($valLenLine);
            if ($valLen > 0) {
                \fread($fp, $valLen);
            }
        }

        return [null, 0];
    }

    /**
     * @param resource $fp
     *
     * @return array{0: ?string, 1: int}
     */
    public static function nextKey($fp, int $cursor): array
    {
        if ($cursor < 0) {
            return [null, 0];
        }
        \fseek($fp, $cursor, \SEEK_SET);
        while (!\feof($fp)) {
            // Skip value belonging to previous key (cursor points at its length line).
            $valLenLine = \fgets($fp);
            if (false === $valLenLine) {
                break;
            }
            $valLen = (int) \trim($valLenLine);
            if ($valLen > 0) {
                \fread($fp, $valLen);
            }
            $keyLenLine = \fgets($fp);
            if (false === $keyLenLine) {
                break;
            }
            $keyLen = (int) \trim($keyLenLine);
            if ($keyLen < 0) {
                break;
            }
            $foundKey = 0 === $keyLen ? '' : \fread($fp, $keyLen);
            if (false === $foundKey) {
                break;
            }
            if ('' !== $foundKey && "\0" !== $foundKey[0]) {
                $newCursor = (int) \ftell($fp);

                return [$foundKey, $newCursor];
            }
            // Deleted key: loop again; next iteration skips this key's value.
        }

        return [null, 0];
    }
}
