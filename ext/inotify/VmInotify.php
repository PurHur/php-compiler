<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for inotify builtins (php-src ext/inotify/inotify.c; #6410).
 */
final class VmInotify
{
    private const EVENT_HEADER_SIZE = 16;

    private const READ_CHUNK = 8192;

    public static function available(): bool
    {
        return InotifyLibcThinAbi::available() && VmPhpFdStream::available();
    }

    /**
     * @return int|false VM stream handle
     */
    public static function init(): int|false
    {
        if (!self::available()) {
            return false;
        }
        $fd = InotifyLibcThinAbi::init();
        if ($fd < 0) {
            return false;
        }
        $handle = VmPhpFdStream::adopt($fd, 'inotify://watch', 'r');
        if (false === $handle) {
            return false;
        }

        return $handle;
    }

    public static function addWatch(int $streamHandle, string $pathname, int $mask): int|false
    {
        $fd = self::fdForStream($streamHandle);
        if (null === $fd) {
            return false;
        }
        $wd = InotifyLibcThinAbi::addWatch($fd, $pathname, $mask);

        return $wd >= 0 ? $wd : false;
    }

    public static function rmWatch(int $streamHandle, int $wd): bool
    {
        $fd = self::fdForStream($streamHandle);
        if (null === $fd) {
            return false;
        }

        return 0 === InotifyLibcThinAbi::rmWatch($fd, $wd);
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    public static function read(int $streamHandle): array|false
    {
        $fd = self::fdForStream($streamHandle);
        if (null === $fd) {
            return false;
        }
        $buf = InotifyLibcThinAbi::readBytes($fd, self::READ_CHUNK);
        if (false === $buf) {
            return false;
        }
        if ('' === $buf) {
            return [];
        }

        return self::parseEventBuffer($buf);
    }

    public static function fdForStream(int $streamHandle): ?int
    {
        if (!VmPhpFdStream::isValidHandle($streamHandle)) {
            return null;
        }

        return VmPhpFdStream::fdForHandle($streamHandle);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseEventBuffer(string $buf): array
    {
        $events = [];
        $len = \strlen($buf);
        $offset = 0;
        while ($offset + self::EVENT_HEADER_SIZE <= $len) {
            $wd = self::unpackSignedInt32($buf, $offset);
            $mask = self::unpackUint32($buf, $offset + 4);
            $cookie = self::unpackUint32($buf, $offset + 8);
            $nameLen = self::unpackUint32($buf, $offset + 12);
            $offset += self::EVENT_HEADER_SIZE;
            $name = '';
            if ($nameLen > 0 && $offset + $nameLen <= $len) {
                $name = \rtrim(\substr($buf, $offset, $nameLen), "\0");
                $offset += $nameLen;
            }
            $event = [
                'wd' => $wd,
                'mask' => $mask,
                'cookie' => $cookie,
            ];
            if ('' !== $name) {
                $event['name'] = $name;
            }
            $events[] = $event;
        }

        return $events;
    }

    private static function unpackSignedInt32(string $buf, int $offset): int
    {
        $parts = \unpack('l', \substr($buf, $offset, 4));

        return (int) ($parts[1] ?? 0);
    }

    private static function unpackUint32(string $buf, int $offset): int
    {
        $parts = \unpack('V', \substr($buf, $offset, 4));

        return (int) ($parts[1] ?? 0);
    }

    /**
     * Build a VM array of inotify event rows for {@see inotify_read}.
     */
    public static function eventsToHashTable(array $events): HashTable
    {
        $table = new HashTable();
        foreach ($events as $i => $event) {
            $row = new HashTable();
            foreach ($event as $key => $value) {
                $slot = new Variable();
                if (\is_int($value)) {
                    $slot->int($value);
                } else {
                    $slot->string((string) $value);
                }
                $row->add((string) $key, $slot);
            }
            $outer = new Variable();
            $outer->array($row);
            $table->addIndex($i, $outer);
        }

        return $table;
    }
}
