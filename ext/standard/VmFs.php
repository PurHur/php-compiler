<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Host filesystem helpers for stdlib builtins (VM). */
final class VmFs
{
    /** @var array<int, resource> */
    private static array $handles = [];

    private static int $nextHandleId = 0;

    /**
     * @param list<string> $names
     */
    public static function stringListToArray(array $names): HashTable
    {
        $ht = new HashTable();
        foreach ($names as $name) {
            $value = new Variable();
            $value->string($name);
            $ht->append($value);
        }

        return $ht;
    }

    public static function fileGetContents(string $path): string|false
    {
        if ('php://input' === $path) {
            return false;
        }
        $data = @file_get_contents($path);
        if (false === $data) {
            return false;
        }

        return $data;
    }

    /**
     * @param string|list<string> $data
     */
    public static function filePutContents(string $path, string|array $data, int $flags = 0): int|false
    {
        if (\is_array($data)) {
            $data = implode('', $data);
        }
        $written = @file_put_contents($path, $data, $flags);
        if (false === $written) {
            return false;
        }

        return $written;
    }

    public static function fopen(string $path, string $mode): int|false
    {
        $fp = @fopen($path, $mode);
        if (false === $fp) {
            return false;
        }
        $id = ++self::$nextHandleId;
        self::$handles[$id] = $fp;

        return $id;
    }

    public static function fread(int $handle, int $length): string|false
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if ($length < 0) {
            return false;
        }

        return @fread($fp, $length);
    }

    public static function fwrite(int $handle, string $data, ?int $length = null): int|false
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (null === $length) {
            $written = @fwrite($fp, $data);
        } else {
            $written = @fwrite($fp, $data, $length);
        }
        if (false === $written) {
            return false;
        }

        return $written;
    }

    public static function fclose(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        unset(self::$handles[$handle]);

        return @fclose($fp);
    }

    private static function lookup(int $handle): mixed
    {
        return self::$handles[$handle] ?? null;
    }
}
