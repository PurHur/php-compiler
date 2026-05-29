<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

/**
 * FastCGI 1.0 record framing (issue #173; php-src sapi/fpm/fpm/fpm_main.c).
 *
 * @see https://fastcgi-archives.github.io/draft-fcgi-spec.html
 */
final class Record
{
    public const VERSION = 1;

    public const BEGIN_REQUEST = 1;
    public const ABORT_REQUEST = 2;
    public const END_REQUEST = 3;
    public const PARAMS = 4;
    public const STDIN = 5;
    public const STDOUT = 6;
    public const STDERR = 7;
    public const DATA = 8;
    public const GET_VALUES = 9;
    public const GET_VALUES_RESULT = 10;
    public const UNKNOWN_TYPE = 11;

    public const ROLE_RESPONDER = 1;
    public const ROLE_GET_FILTER = 2;
    public const ROLE_POST_FILTER = 3;

    public const PROTOCOL_STATUS_REQUEST_COMPLETE = 0;
    public const PROTOCOL_STATUS_CANT_MPX_CONN = 1;
    public const PROTOCOL_STATUS_OVERLOADED = 2;
    public const PROTOCOL_STATUS_UNKNOWN_ROLE = 3;

    public const HEADER_SIZE = 8;

    /**
     * @return list<array{type: int, requestId: int, content: string}>
     */
    public static function decodeAll(string $buffer): array
    {
        $records = [];
        $offset = 0;
        $len = strlen($buffer);
        while ($offset < $len) {
            $remaining = $len - $offset;
            if ($remaining < self::HEADER_SIZE) {
                break;
            }
            $parsed = self::decodeOne(substr($buffer, $offset));
            $records[] = $parsed;
            $offset += self::HEADER_SIZE
                + strlen($parsed['content'])
                + self::paddingLength(strlen($parsed['content']));
        }

        return $records;
    }

    /**
     * @return array{type: int, requestId: int, content: string}
     */
    public static function decodeOne(string $buffer): array
    {
        if (strlen($buffer) < self::HEADER_SIZE) {
            throw new \InvalidArgumentException('FastCGI record header too short');
        }
        $version = ord($buffer[0]);
        if (self::VERSION !== $version) {
            throw new \InvalidArgumentException('Unsupported FastCGI version: '.$version);
        }
        $type = ord($buffer[1]);
        $requestId = (ord($buffer[2]) << 8) | ord($buffer[3]);
        $contentLength = (ord($buffer[4]) << 8) | ord($buffer[5]);
        $paddingLength = ord($buffer[6]);
        $need = self::HEADER_SIZE + $contentLength + $paddingLength;
        if (strlen($buffer) < $need) {
            throw new \InvalidArgumentException('FastCGI record truncated');
        }
        $content = substr($buffer, self::HEADER_SIZE, $contentLength);

        return [
            'type' => $type,
            'requestId' => $requestId,
            'content' => $content,
        ];
    }

    public static function encode(int $type, int $requestId, string $content): string
    {
        $contentLength = strlen($content);
        $paddingLength = self::paddingLength($contentLength);
        $header = pack(
            'CCnnCC',
            self::VERSION,
            $type,
            $requestId & 0xffff,
            $contentLength,
            $paddingLength,
            0
        );
        $padding = '' !== $paddingLength ? str_repeat("\0", $paddingLength) : '';

        return $header.$content.$padding;
    }

    public static function encodeBeginRequest(int $requestId, int $role, int $flags = 0): string
    {
        $body = pack('nC', $role & 0xffff, $flags & 0xff).str_repeat("\0", 5);

        return self::encode(self::BEGIN_REQUEST, $requestId, $body);
    }

    public static function encodeEndRequest(int $requestId, int $appStatus, int $protocolStatus): string
    {
        $body = pack('NC', $appStatus & 0xffffffff, $protocolStatus & 0xff).str_repeat("\0", 3);

        return self::encode(self::END_REQUEST, $requestId, $body);
    }

    public static function encodeStdout(int $requestId, string $payload): string
    {
        return self::encode(self::STDOUT, $requestId, $payload);
    }

    public static function encodeStderr(int $requestId, string $payload): string
    {
        return self::encode(self::STDERR, $requestId, $payload);
    }

    public static function encodeParams(int $requestId, string $paramsBody): string
    {
        return self::encode(self::PARAMS, $requestId, $paramsBody);
    }

    public static function encodeStdin(int $requestId, string $stdinChunk): string
    {
        return self::encode(self::STDIN, $requestId, $stdinChunk);
    }

    /**
     * Split payload into ≤65535-byte STDOUT records.
     *
     * @return list<string>
     */
    public static function encodeStdoutChunks(int $requestId, string $payload): array
    {
        if ('' === $payload) {
            return [self::encodeStdout($requestId, '')];
        }
        $chunks = [];
        $max = 65535;
        $offset = 0;
        $len = strlen($payload);
        while ($offset < $len) {
            $piece = substr($payload, $offset, $max);
            $chunks[] = self::encodeStdout($requestId, $piece);
            $offset += strlen($piece);
        }

        return $chunks;
    }

    public static function paddingLength(int $contentLength): int
    {
        return (8 - ($contentLength % 8)) % 8;
    }

    /**
     * Read exactly one record from a stream (blocking).
     *
     * @param resource $stream
     *
     * @return array{type: int, requestId: int, content: string}|null null on EOF before header
     */
    public static function readFromStream($stream): ?array
    {
        $header = self::readExact($stream, self::HEADER_SIZE);
        if (null === $header) {
            return null;
        }
        $contentLength = (ord($header[4]) << 8) | ord($header[5]);
        $paddingLength = ord($header[6]);
        $content = self::readExact($stream, $contentLength);
        if (null === $content) {
            throw new \RuntimeException('FastCGI record body truncated');
        }
        if ($paddingLength > 0) {
            $pad = self::readExact($stream, $paddingLength);
            if (null === $pad) {
                throw new \RuntimeException('FastCGI record padding truncated');
            }
        }

        return [
            'type' => ord($header[1]),
            'requestId' => (ord($header[2]) << 8) | ord($header[3]),
            'content' => $content,
        ];
    }

    /**
     * @param resource $stream
     */
    private static function readExact($stream, int $length): ?string
    {
        if ($length <= 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if (false === $chunk || '' === $chunk) {
                return '' === $data ? null : null;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
