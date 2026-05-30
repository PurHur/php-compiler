<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

/**
 * FastCGI name-value pair encoding for FCGI_PARAMS (issue #173).
 */
final class ParamsCodec
{
    /**
     * @param array<string, string> $params
     */
    public static function encode(array $params): string
    {
        $out = '';
        foreach ($params as $name => $value) {
            $out .= self::encodeLength(strlen($name)).$name;
            $out .= self::encodeLength(strlen($value)).$value;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function decode(string $payload): array
    {
        $params = [];
        $offset = 0;
        $len = strlen($payload);
        while ($offset < $len) {
            [$nameLen, $offset] = self::decodeLength($payload, $offset);
            if ($offset + $nameLen > $len) {
                throw new \InvalidArgumentException('FastCGI params: name truncated');
            }
            $name = substr($payload, $offset, $nameLen);
            $offset += $nameLen;
            [$valueLen, $offset] = self::decodeLength($payload, $offset);
            if ($offset + $valueLen > $len) {
                throw new \InvalidArgumentException('FastCGI params: value truncated');
            }
            $value = substr($payload, $offset, $valueLen);
            $offset += $valueLen;
            $params[$name] = $value;
        }

        return $params;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        return pack('N', $length | 0x80000000);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function decodeLength(string $payload, int $offset): array
    {
        if ($offset >= strlen($payload)) {
            throw new \InvalidArgumentException('FastCGI params: length missing');
        }
        $byte = ord($payload[$offset]);
        if ($byte < 128) {
            return [$byte, $offset + 1];
        }
        if ($offset + 4 > strlen($payload)) {
            throw new \InvalidArgumentException('FastCGI params: 4-byte length truncated');
        }
        $word = unpack('N', substr($payload, $offset, 4));
        $length = ($word[1] & 0x7fffffff);

        return [$length, $offset + 4];
    }
}
