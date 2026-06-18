<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native php://memory and php://temp streams without host PHP @fopen (#4969, ext/standard/streams.c).
 *
 * php-src: php_stream_memory_create, php_stream_temp_create
 */
final class VmPhpMemoryStream
{
    /** @var array<int, PhpMemoryStreamState> */
    private static array $streams = [];

    private static int $nextHandleId = 0;

    public static function isSupportedUri(string $uri): bool
    {
        if ('php://memory' === $uri) {
            return true;
        }

        return \str_starts_with($uri, 'php://temp');
    }

    public static function open(string $uri, string $mode): int|false
    {
        $flags = self::parseMode($mode);
        if (null === $flags) {
            return false;
        }

        $id = ++self::$nextHandleId;
        self::$streams[$id] = new PhpMemoryStreamState($uri, $flags);

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

    public static function uriForHandle(int $handle): string
    {
        return self::$streams[$handle]->uri ?? '';
    }

    public static function protocolForHandle(int $handle): string
    {
        $uri = self::uriForHandle($handle);

        return \str_starts_with($uri, 'php://temp') ? 'temp' : 'memory';
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

        $remaining = \strlen($state->buffer) - $state->position;
        if ($remaining <= 0) {
            $state->atEof = true;

            return '';
        }
        $take = \min($length, $remaining);
        $chunk = \substr($state->buffer, $state->position, $take);
        $state->position += $take;

        return $chunk;
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

        if ($state->append) {
            $state->position = \strlen($state->buffer);
        }

        $pos = $state->position;
        $bufLen = \strlen($state->buffer);
        if ($pos > $bufLen) {
            $state->buffer .= \str_repeat("\0", $pos - $bufLen);
        }

        $written = \strlen($data);
        $state->buffer = \substr($state->buffer, 0, $pos).$data.\substr($state->buffer, $pos + $written);
        $state->position = $pos + $written;
        $state->atEof = false;

        return $written;
    }

    public static function seek(int $handle, int $offset, int $whence): int
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return -1;
        }

        $len = \strlen($state->buffer);
        $pos = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $state->position + $offset,
            \SEEK_END => $len + $offset,
            default => -1,
        };
        if ($pos < 0) {
            return -1;
        }
        $state->position = $pos;
        $state->atEof = false;

        return 0;
    }

    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        return $state->position;
    }

    public static function eof(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return true;
        }

        return $state->atEof;
    }

    public static function bufferLength(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        return \strlen($state->buffer);
    }

    public static function close(int $handle): bool
    {
        if (!isset(self::$streams[$handle])) {
            return false;
        }
        unset(self::$streams[$handle]);

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
        if ($offset >= 0 && 0 !== self::seek($handle, $offset, \SEEK_SET)) {
            return false;
        }
        if ($maxlength < 0) {
            $remaining = \strlen($state->buffer) - $state->position;
            if ($remaining <= 0) {
                return '';
            }
            $data = \substr($state->buffer, $state->position, $remaining);
            $state->position += \strlen($data);

            return $data;
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
     * @return array{canRead: bool, canWrite: bool, truncate: bool, append: bool}|null
     */
    private static function parseMode(string $mode): ?array
    {
        $mode = \strtolower($mode);
        $mode = \strtr($mode, ['b' => '', 't' => '']);
        if ('' === $mode) {
            return null;
        }

        $read = false;
        $write = false;
        $truncate = false;
        $append = false;

        $first = $mode[0];
        $plus = \str_contains($mode, '+');
        switch ($first) {
            case 'r':
                $read = true;
                $write = $plus;
                break;
            case 'w':
                $write = true;
                $truncate = true;
                $read = $plus;
                break;
            case 'a':
                $write = true;
                $append = true;
                $read = $plus;
                break;
            case 'x':
                $write = true;
                $truncate = true;
                $read = $plus;
                break;
            case 'c':
                $write = true;
                $read = $plus;
                break;
            default:
                return null;
        }

        if (!$read && !$write) {
            return null;
        }

        return [
            'canRead' => $read,
            'canWrite' => $write,
            'truncate' => $truncate,
            'append' => $append,
        ];
    }
}

final class PhpMemoryStreamState
{
    public string $buffer = '';

    public int $position = 0;

    /** Set after a read returns no data at end-of-file (php_stream_memory.c). */
    public bool $atEof = false;

    public bool $canRead;

    public bool $canWrite;

    public bool $append;

    public function __construct(
        public readonly string $uri,
        array $flags,
    ) {
        $this->canRead = $flags['canRead'];
        $this->canWrite = $flags['canWrite'];
        $this->append = $flags['append'];
        if ($flags['truncate']) {
            $this->buffer = '';
            $this->position = 0;
        }
        if ($this->append) {
            $this->position = 0;
        }
    }
}
