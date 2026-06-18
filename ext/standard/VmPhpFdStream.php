<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native libc fd stream handles without host PHP fopen on php fd URLs (#8533, ext/standard/streams.c).
 * flock/fflush/fsync via libc FFI — no host @flock/@fsync (#8594).
 *
 * php-src: php_stream_fd_ops — adopted dup(2) fds for open/popen/tmpfile/socket paths.
 */
final class VmPhpFdStream
{
    private const SEEK_SET = 0;

    private const SEEK_CUR = 1;

    private const SEEK_END = 2;

    private const CHUNK = 8192;

    /** PHP LOCK_* operands (ext/standard/flock.c). */
    private const PHP_LOCK_SH = 1;

    private const PHP_LOCK_EX = 2;

    private const PHP_LOCK_UN = 3;

    private const PHP_LOCK_NB = 4;

    /** Linux flock(2) flags. */
    private const SYS_LOCK_SH = 1;

    private const SYS_LOCK_EX = 2;

    private const SYS_LOCK_NB = 4;

    private const SYS_LOCK_UN = 8;

    /** @var array<int, PhpFdStreamState> */
    private static array $streams = [];

    private static int $nextHandleId = 0;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function isFdUri(string $uri): bool
    {
        return \str_starts_with($uri, 'php://fd/');
    }

    /**
     * fopen('php://fd/N') — dup OS fd N and register a VM stream handle (#8533 phase 2).
     *
     * php-src: main/streams/php_stream_fd_wrapper.c
     *
     * @return int|false VM stream handle id
     */
    public static function openFromUri(string $uri, string $mode): int|false
    {
        if (!self::isFdUri($uri)) {
            return false;
        }
        $fdStr = \substr($uri, 9);
        if ('' === $fdStr || !\ctype_digit($fdStr)) {
            return false;
        }
        $sourceFd = (int) $fdStr;
        if ($sourceFd < 0) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $dupFd = (int) $ffi->dup($sourceFd);
            if ($dupFd < 0) {
                return false;
            }
            $handle = self::adopt($dupFd, $uri, self::phpStreamMode($mode));
            if (false === $handle) {
                self::closeFd($dupFd);

                return false;
            }

            return $handle;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Take ownership of a dup'd fd and register a VM stream handle.
     */
    public static function adopt(int $fd, string $uri, string $mode): int|false
    {
        if ($fd < 0 || !self::available()) {
            return false;
        }
        $flags = self::parseMode($mode);
        if (null === $flags) {
            self::closeFd($fd);

            return false;
        }

        $id = ++self::$nextHandleId;
        self::$streams[$id] = new PhpFdStreamState($fd, $uri, $flags['canRead'], $flags['canWrite']);

        return $id;
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    public static function fdForHandle(int $handle): ?int
    {
        return self::$streams[$handle]->fd ?? null;
    }

    public static function uriForHandle(int $handle): string
    {
        return self::$streams[$handle]->uri ?? '';
    }

    public static function read(int $handle, int $length): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canRead || $length < 0) {
            return false;
        }
        if (0 === $length) {
            return '';
        }
        if ($state->eof) {
            return '';
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $buf = $ffi->new('char['.self::CHUNK.']');
            $parts = [];
            $remaining = $length;
            while ($remaining > 0) {
                $chunk = min(self::CHUNK, $remaining);
                $n = (int) $ffi->read($state->fd, \FFI::addr($buf[0]), $chunk);
                if ($n < 0) {
                    return false;
                }
                if (0 === $n) {
                    $state->eof = true;
                    break;
                }
                $parts[] = \FFI::string($buf, $n);
                $remaining -= $n;
            }

            return '' === $parts ? '' : implode('', $parts);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function write(int $handle, string $data, ?int $length = null): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canWrite) {
            return false;
        }
        if (null !== $length && $length < 0) {
            return 0;
        }
        if (null !== $length && $length < \strlen($data)) {
            $data = \substr($data, 0, $length);
        }
        if ('' === $data) {
            return 0;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $len = \strlen($data);
            $buf = $ffi->new('char['.$len.']');
            \FFI::memcpy($buf, $data, $len);
            $written = 0;
            while ($written < $len) {
                $n = (int) $ffi->write($state->fd, \FFI::addr($buf[$written]), $len - $written);
                if ($n <= 0) {
                    return 0 === $written ? false : $written;
                }
                $written += $n;
            }
            $state->eof = false;

            return $written;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function seek(int $handle, int $offset, int $whence): int
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return -1;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        try {
            $pos = (int) $ffi->lseek($state->fd, $offset, $whence);
            if ($pos < 0) {
                return -1;
            }
            $state->eof = false;

            return 0;
        } catch (\Throwable) {
            return -1;
        }
    }

    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $pos = (int) $ffi->lseek($state->fd, 0, self::SEEK_CUR);

            return $pos >= 0 ? $pos : false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function eof(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return true;
        }

        return $state->eof;
    }

    /** flock() on adopted fd — libc flock(2), no host PHP @flock (#8594, ext/standard/flock.c). */
    public static function flock(int $handle, int $operation): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->flock($state->fd, self::phpLockToSys($operation));
        } catch (\Throwable) {
            return false;
        }
    }

    /** fflush() — direct write(2) path has no userspace buffer; parity true (#8594). */
    public static function fflush(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    /** fsync() — libc fsync(2) on stream fd (#8594, ext/standard/file.c). */
    public static function fsync(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->fsync($state->fd);
        } catch (\Throwable) {
            return false;
        }
    }

    /** fdatasync() — libc fdatasync(2) on stream fd (#8594, ext/standard/file.c). */
    public static function fdatasync(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->fdatasync($state->fd);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function close(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        unset(self::$streams[$handle]);
        self::closeFd($state->fd);

        return true;
    }

    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canRead) {
            return false;
        }
        if ($offset < -1) {
            return false;
        }
        if ($offset >= 0 && 0 !== self::seek($handle, $offset, self::SEEK_SET)) {
            return false;
        }
        if ($maxlength < 0) {
            $parts = [];
            while (!$state->eof) {
                $chunk = self::read($handle, self::CHUNK);
                if (false === $chunk) {
                    return false;
                }
                if ('' === $chunk) {
                    break;
                }
                $parts[] = $chunk;
            }

            return '' === $parts ? '' : implode('', $parts);
        }
        if (0 === $maxlength) {
            return '';
        }

        return self::read($handle, $maxlength);
    }

    public static function fgets(int $handle, ?int $length = null): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canRead) {
            return false;
        }
        $maxLen = null === $length ? 8192 : $length;
        if ($maxLen <= 0) {
            return false;
        }

        $line = '';
        while (\strlen($line) < $maxLen) {
            $byte = self::read($handle, 1);
            if (false === $byte || '' === $byte) {
                break;
            }
            $line .= $byte;
            if ("\n" === $byte) {
                break;
            }
        }
        if ('' === $line && self::eof($handle)) {
            return false;
        }

        return $line;
    }

    public static function streamGetLine(int $handle, int $maxLength, ?string $ending = null): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canRead) {
            return false;
        }
        if ($maxLength < 0) {
            return false;
        }
        if (0 === $maxLength) {
            $maxLength = 8192;
        }
        if (null === $ending || '' === $ending) {
            $data = self::read($handle, $maxLength);
            if (false === $data || ('' === $data && self::eof($handle))) {
                return false;
            }

            return $data;
        }

        $result = '';
        $endingLen = \strlen($ending);
        while (\strlen($result) < $maxLength) {
            $byte = self::read($handle, 1);
            if (false === $byte || '' === $byte) {
                break;
            }
            $result .= $byte;
            if ($endingLen > 0 && \substr($result, -$endingLen) === $ending) {
                return \substr($result, 0, -$endingLen);
            }
        }
        if ('' === $result && self::eof($handle)) {
            return false;
        }

        return $result;
    }

    /**
     * @return array{canRead: bool, canWrite: bool}|null
     */
    private static function phpStreamMode(string $mode): string
    {
        if ('' === $mode) {
            return 'rb';
        }
        if (!\str_contains($mode, 'b')) {
            return $mode.'b';
        }

        return $mode;
    }

    private static function parseMode(string $mode): ?array
    {
        $mode = \strtolower($mode);
        $mode = \strtr($mode, ['b' => '', 't' => '']);
        if ('' === $mode) {
            return null;
        }

        $read = false;
        $write = false;
        $first = $mode[0];
        $plus = \str_contains($mode, '+');
        switch ($first) {
            case 'r':
                $read = true;
                $write = $plus;
                break;
            case 'w':
            case 'x':
            case 'c':
                $write = true;
                $read = $plus;
                break;
            case 'a':
                $write = true;
                $read = $plus;
                break;
            default:
                return null;
        }

        return ['canRead' => $read, 'canWrite' => $write];
    }

    private static function phpLockToSys(int $operation): int
    {
        $hasUn = ($operation & self::PHP_LOCK_UN) === self::PHP_LOCK_UN;
        $opWithoutUn = $hasUn ? ($operation & ~self::PHP_LOCK_UN) : $operation;
        $sys = 0;
        if ($hasUn) {
            $sys |= self::SYS_LOCK_UN;
        }
        if (0 !== ($opWithoutUn & self::PHP_LOCK_SH)) {
            $sys |= self::SYS_LOCK_SH;
        }
        if (0 !== ($opWithoutUn & self::PHP_LOCK_EX)) {
            $sys |= self::SYS_LOCK_EX;
        }
        if (0 !== ($opWithoutUn & self::PHP_LOCK_NB)) {
            $sys |= self::SYS_LOCK_NB;
        }

        return $sys;
    }

    private static function closeFd(int $fd): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }

        try {
            $ffi->close($fd);
        } catch (\Throwable) {
        }
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef long ssize_t;
typedef unsigned long size_t;
typedef long off_t;
ssize_t read(int fd, void *buf, size_t count);
ssize_t write(int fd, const void *buf, size_t count);
off_t lseek(int fd, off_t offset, int whence);
int close(int fd);
int dup(int oldfd);
int flock(int fd, int operation);
int fsync(int fd);
int fdatasync(int fd);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}

final class PhpFdStreamState
{
    public bool $eof = false;

    public function __construct(
        public readonly int $fd,
        public readonly string $uri,
        public readonly bool $canRead,
        public readonly bool $canWrite,
    ) {
    }
}
