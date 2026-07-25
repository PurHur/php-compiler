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
    public const DEFAULT_CHUNK_SIZE = 8192;

    /** @var array<int, PhpMemoryStreamState> */
    private static array $streams = [];

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

        $id = VmFs::allocateStreamHandleId();
        $state = new PhpMemoryStreamState($uri, $flags);
        // php-src php_stream_memory: buffer reads work after rewind even for write-only modes (#11636).
        $state->canRead = true;
        self::$streams[$id] = $state;

        return $id;
    }

    /**
     * Open an in-memory stream with a pre-filled buffer (data:// wrapper, #10263).
     */
    public static function openWithBuffer(string $uri, string $buffer, string $mode): int|false
    {
        $flags = self::parseMode($mode);
        if (null === $flags) {
            return false;
        }

        $id = VmFs::allocateStreamHandleId();
        $state = new PhpMemoryStreamState($uri, $flags);
        $state->canRead = true;
        if ($flags['truncate']) {
            $state->buffer = '';
        } else {
            $state->buffer = $buffer;
        }
        $state->position = $flags['append'] ? \strlen($state->buffer) : 0;
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

    public static function uriForHandle(int $handle): string
    {
        return self::$streams[$handle]->uri ?? '';
    }

    public static function protocolForHandle(int $handle): string
    {
        $uri = self::uriForHandle($handle);

        return \str_starts_with($uri, 'php://temp') ? 'temp' : 'memory';
    }

    /**
     * Cast php://temp to a selectable OS fd via VmPhpFdStream mkstemp (#19691).
     * php://memory stays non-selectable (#19688). Caller must close the fd after select.
     */
    public static function castFdForSelect(int $handle): ?int
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !\str_starts_with($state->uri, 'php://temp')) {
            return null;
        }
        if (!VmPhpFdStream::available()) {
            return null;
        }
        $fd = VmPhpFdStream::openUnlinkedTempFd();
        if (null === $fd) {
            return null;
        }
        if ('' !== $state->buffer && !VmPhpFdStream::writeAllRawFd($fd, $state->buffer)) {
            VmPhpFdStream::closeRawFd($fd);

            return null;
        }
        $pos = $state->position;
        if ($pos < 0) {
            $pos = 0;
        }
        if (!VmPhpFdStream::seekRawFd($fd, $pos)) {
            VmPhpFdStream::closeRawFd($fd);

            return null;
        }

        return $fd;
    }

    /**
     * Cast php://temp to a selectable host tempfile when Pure/FFI fd cast is unavailable (#19688/#19691).
     * php://memory stays non-selectable. Caller must fclose after select.
     *
     * @return resource|null
     */
    public static function castHostResourceForSelect(int $handle)
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !\str_starts_with($state->uri, 'php://temp')) {
            return null;
        }
        if (!\function_exists('tmpfile')) {
            return null;
        }
        $tf = @\tmpfile();
        if (!\is_resource($tf)) {
            return null;
        }
        if ('' !== $state->buffer) {
            $written = @\fwrite($tf, $state->buffer);
            if (false === $written) {
                @\fclose($tf);

                return null;
            }
        }
        $pos = $state->position;
        if ($pos < 0) {
            $pos = 0;
        }
        if (-1 === @\fseek($tf, $pos)) {
            @\fclose($tf);

            return null;
        }

        return $tf;
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
        if ($length > $remaining) {
            $state->atEof = true;
        }

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

        // php-src main/streams/memory.c — php_stream_memory_seek: reject past fsize (#21986).
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
        $state->atEof = false;

        return 0;
    }

    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        if ($state->position > \strlen($state->buffer)) {
            return false;
        }

        return $state->position;
    }

    /**
     * Truncate in-memory buffer (php-src main/streams/php_stream_memory.c).
     */
    public static function truncate(int $handle, int $size): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canWrite || $size < 0) {
            return false;
        }

        $len = \strlen($state->buffer);
        if ($size < $len) {
            $state->buffer = \substr($state->buffer, 0, $size);
        } elseif ($size > $len) {
            $state->buffer .= \str_repeat("\0", $size - $len);
        }
        if ($state->position > $size) {
            $state->position = $size;
        }
        $state->atEof = false;

        return true;
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

    /**
     * fflush() — in-memory streams have no userspace buffer; parity true (#10712, ext/standard/streams.c).
     */
    public static function flush(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    /**
     * stream_set_chunk_size() for php://memory|temp — php-src ext/standard/streams.c (#10459).
     *
     * @return int|false previous chunk size
     */
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

    /**
     * stream_set_write_buffer() / set_file_buffer() for php://memory|temp (#12532, php-src streamsfuncs.c).
     *
     * @return int|false previous buffer size (-1 on memory streams)
     */
    public static function setWriteBuffer(int $handle, int $buffer): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        return -1;
    }

    /**
     * stream_set_read_buffer() for php://memory|temp (#10489, php-src streamsfuncs.c).
     *
     * @return int|false previous buffer size (0 on memory streams)
     */
    public static function setReadBuffer(int $handle, int $buffer): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        return 0;
    }

    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || !$state->canRead) {
            return false;
        }
        // php-src file.c: only offset >= 0 seeks; negative (incl. < -1) keeps current pos (#23190).
        if ($offset >= 0 && 0 !== self::seek($handle, $offset, \SEEK_SET)) {
            // php-src ext/standard/file.c — PHP_FUNCTION(stream_get_contents) (#21986).
            VmFs::warnStreamGetContentsSeekFailed($offset);

            return false;
        }
        if ($maxlength < 0) {
            $remaining = \strlen($state->buffer) - $state->position;
            if ($remaining <= 0) {
                $state->atEof = true;

                return '';
            }
            $data = \substr($state->buffer, $state->position, $remaining);
            $state->position += \strlen($data);
            $state->atEof = true;

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
        if (null === $length) {
            $maxLen = 8192;
        } else {
            if ($length <= 0) {
                return false;
            }
            // php-src php_stream_fgets: at most $length - 1 bytes before newline/EOF.
            $maxLen = $length - 1;
            if ($maxLen <= 0) {
                return false;
            }
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
        if ($state->position >= \strlen($state->buffer)) {
            $state->atEof = true;
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
    public static function isValidMode(string $mode): bool
    {
        return null !== self::parseMode($mode);
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
    public int $chunkSize = 8192;

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
