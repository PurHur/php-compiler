<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\ext\standard\VmFs;

/**
 * SQLite3 BLOB incremental I/O stream (php-src php_stream_sqlite3_ops; #20599).
 *
 * Label "SQLite3" matches php_stream_ops; get_resource_type() still reports "stream".
 */
final class VmSqlite3BlobStream
{
    /** @var array<int, Sqlite3BlobStreamState> */
    private static array $streams = [];

    /**
     * @param \FFI\CData $blob sqlite3_blob*
     */
    public static function open($blob, int $flags, int $size): int
    {
        $id = VmFs::allocateStreamHandleId();
        $state = new Sqlite3BlobStreamState();
        $state->blob = $blob;
        $state->flags = $flags;
        $state->size = $size;
        $state->position = 0;
        $state->canWrite = ($flags & Sqlite3Constants::OPEN_READWRITE) !== 0;
        self::$streams[$id] = $state;

        return $id;
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    /** @return list<int> */
    public static function openHandleIds(): array
    {
        return \array_keys(self::$streams);
    }

    public static function read(int $handle, int $length): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || null === $state->blob) {
            return false;
        }
        if ($length <= 0) {
            return '';
        }
        $remaining = $state->size - $state->position;
        if ($remaining <= 0) {
            $state->eof = true;

            return '';
        }
        $count = $length;
        if ($state->position + $count >= $state->size) {
            $count = $remaining;
            $state->eof = true;
        }
        if ($count <= 0) {
            return '';
        }
        $data = VmSqlite3Native::blobRead($state->blob, $count, $state->position);
        if (null === $data) {
            return false;
        }
        $state->position += \strlen($data);

        return $data;
    }

    public static function write(int $handle, string $data, ?int $length = null): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || null === $state->blob) {
            return false;
        }
        if (!$state->canWrite) {
            // php-src: "Can't write to blob stream: is open as read only"
            return false;
        }
        $count = null === $length ? \strlen($data) : $length;
        if ($count < 0) {
            return false;
        }
        if ($count > \strlen($data)) {
            $count = \strlen($data);
        }
        if ($state->position + $count > $state->size) {
            // php-src: cannot grow a BLOB via the stream
            return false;
        }
        $chunk = \substr($data, 0, $count);
        if (!VmSqlite3Native::blobWrite($state->blob, $chunk, $state->position)) {
            return false;
        }
        $state->position += $count;
        if ($state->position >= $state->size) {
            $state->eof = true;
            $state->position = $state->size;
        }

        return $count;
    }

    public static function close(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        if (null !== $state->blob) {
            VmSqlite3Native::blobClose($state->blob);
            $state->blob = null;
        }
        unset(self::$streams[$handle]);

        return true;
    }

    public static function eof(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return true;
        }

        return $state->eof || $state->position >= $state->size;
    }

    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        return $state->position;
    }

    public static function seek(int $handle, int $offset, int $whence): int
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return -1;
        }
        $size = $state->size;
        $pos = $state->position;
        switch ($whence) {
            case \SEEK_SET:
                if ($offset < 0 || $offset > $size) {
                    $state->position = $size;

                    return -1;
                }
                $state->position = $offset;
                break;
            case \SEEK_CUR:
                $next = $pos + $offset;
                if ($next < 0 || $next > $size) {
                    $state->position = $next < 0 ? 0 : $size;

                    return -1;
                }
                $state->position = $next;
                break;
            case \SEEK_END:
                $next = $size + $offset;
                if ($offset > 0 || $next < 0) {
                    $state->position = $offset > 0 ? $size : 0;

                    return -1;
                }
                $state->position = $next;
                break;
            default:
                return -1;
        }
        $state->eof = false;

        return 0;
    }

    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1): string|false
    {
        if ($offset >= 0 && 0 !== self::seek($handle, $offset, \SEEK_SET)) {
            \PHPCompiler\ext\standard\VmFs::warnStreamGetContentsSeekFailed($offset);

            return false;
        }
        if ($maxlength < 0) {
            $out = '';
            while (!self::eof($handle)) {
                $chunk = self::read($handle, 8192);
                if (false === $chunk) {
                    return false;
                }
                if ('' === $chunk) {
                    break;
                }
                $out .= $chunk;
            }

            return $out;
        }
        if (0 === $maxlength) {
            return '';
        }

        return self::read($handle, $maxlength);
    }
}

/** @internal */
final class Sqlite3BlobStreamState
{
    /** @var \FFI\CData|null sqlite3_blob* */
    public $blob = null;

    public int $flags = 0;

    public int $size = 0;

    public int $position = 0;

    public bool $canWrite = false;

    public bool $eof = false;
}
