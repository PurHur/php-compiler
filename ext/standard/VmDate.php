<?php

declare(strict_types=1);

/**
 * VM date/time helpers (host libc clock via PHP date/gmdate/time for parity with PHP 8.2).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

final class VmDate
{
    public static function time(): int
    {
        return (int) \time();
    }

    public static function getmypid(): int
    {
        return (int) \getmypid();
    }

    /** getmygrgid() — real group id (ext/standard/basic_functions.c, #3611). */
    public static function getmygrgid(): int
    {
        if (\function_exists('posix_getgid')) {
            return (int) \posix_getgid();
        }
        if (\function_exists('getgid')) {
            return (int) \getgid();
        }

        throw new \LogicException('getmygrgid() requires POSIX support in this compiler build');
    }

    /**
     * getmyinode() — inode of the executed script (ext/standard/basic_functions.c, #3611).
     *
     * @return int|false
     */
    public static function getmyinode(Frame $frame)
    {
        $path = self::executedFilename($frame);
        if ('' === $path || '-' === $path) {
            return false;
        }
        $stat = @\stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) ($stat['ino'] ?? 0);
    }

    private static function executedFilename(Frame $frame): string
    {
        if (null !== $frame->vmContext) {
            $root = $frame->vmContext->scriptStack->root();
            if ('' !== $root) {
                return $root;
            }
        }
        $f = $frame;
        while (null !== $f->parent) {
            $f = $f->parent;
        }
        if ('' !== $f->scriptPath) {
            return $f->scriptPath;
        }
        if (null !== $f->block) {
            return $f->block->scriptPath();
        }

        return '';
    }

    public static function date(string $format, ?int $timestamp = null): string
    {
        return \date($format, $timestamp ?? self::time());
    }

    public static function gmdate(string $format, ?int $timestamp = null): string
    {
        return \gmdate($format, $timestamp ?? self::time());
    }

    /** @return string|float */
    public static function microtime(bool $asFloat = false)
    {
        return \microtime($asFloat);
    }

    /**
     * @return int|array{0: int, 1: int}
     */
    public static function hrtime(bool $asNumber = false)
    {
        return \hrtime($asNumber);
    }

    public static function getdate(?int $timestamp = null): HashTable
    {
        $raw = \getdate($timestamp ?? self::time());
        $ht = new HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } else {
                $slot->string((string) $value);
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    public static function gettimeofdayFloat(): float
    {
        return (float) \gettimeofday(true);
    }

    public static function gettimeofdayArray(): HashTable
    {
        /** @var array{sec: int, usec: int, minuteswest: int, dsttime: int} $data */
        $data = \gettimeofday();
        $ht = new HashTable();
        foreach (['sec', 'usec', 'minuteswest', 'dsttime'] as $key) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int((int) $data[$key]);
            $ht->add($key, $var);
        }

        return $ht;
    }
}
