<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\Web\Superglobals;

/**
 * Native php://input and php://output streams without host PHP @fopen (#8492, ext/standard/streams.c).
 *
 * php-src: php_stream_input_ops, php_stream_output_ops
 */
final class VmPhpInputOutputStream
{
    /** @var array<int, PhpInputOutputStreamState> */
    private static array $streams = [];

    private static int $nextHandleId = 0;

    public static function isSupportedUri(string $uri): bool
    {
        return 'php://input' === $uri || 'php://output' === $uri;
    }

    public static function open(string $uri, string $mode): int|false
    {
        $flags = self::parseMode($uri, $mode);
        if (null === $flags) {
            return false;
        }

        $id = ++self::$nextHandleId;
        self::$streams[$id] = new PhpInputOutputStreamState($uri, $flags);

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

        OutputBuffer::append($data);
        $state->bytesWritten += \strlen($data);

        return \strlen($data);
    }

    public static function seek(int $handle, int $offset, int $whence): int
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canRead) {
            return -1;
        }

        $len = \strlen($state->buffer);
        $pos = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $state->position + $offset,
            \SEEK_END => $len + $offset,
            default => -1,
        };
        if ($pos < 0 || $pos > $len) {
            return -1;
        }
        $state->position = $pos;

        return 0;
    }

    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        if (!$state->canRead) {
            return $state->bytesWritten;
        }

        return $state->position;
    }

    public static function eof(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return true;
        }
        if (!$state->canRead) {
            return true;
        }

        return $state->position >= \strlen($state->buffer);
    }

    public static function close(int $handle): bool
    {
        if (!isset(self::$streams[$handle])) {
            return false;
        }
        unset(self::$streams[$handle]);

        return true;
    }

    /** @return int|false previous chunk size */
    public static function setChunkSize(int $handle, int $chunkSize): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        if ($chunkSize <= 0) {
            throw new \ValueError('stream_set_chunk_size(): Argument #2 ($size) must be greater than 0');
        }
        $previous = $state->chunkSize;
        $state->chunkSize = $chunkSize;

        return $previous;
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

    /**
     * @return array{canRead: bool, canWrite: bool}|null
     */
    private static function parseMode(string $uri, string $mode): ?array
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
            case 'a':
            case 'x':
            case 'c':
                $write = true;
                $read = $plus;
                break;
            default:
                return null;
        }

        if ('php://input' === $uri) {
            if (!$read || $write) {
                return null;
            }
        } elseif ('php://output' === $uri) {
            if (!$write || $read) {
                return null;
            }
        } else {
            return null;
        }

        return [
            'canRead' => $read,
            'canWrite' => $write,
        ];
    }
}

final class PhpInputOutputStreamState
{
    public int $chunkSize = VmPhpMemoryStream::DEFAULT_CHUNK_SIZE;

    public string $buffer;

    public int $position = 0;

    public int $bytesWritten = 0;

    public bool $canRead;

    public bool $canWrite;

    public function __construct(
        public readonly string $uri,
        array $flags,
    ) {
        $this->canRead = $flags['canRead'];
        $this->canWrite = $flags['canWrite'];
        $this->buffer = $this->canRead ? Superglobals::readRequestBody() : '';
    }
}
