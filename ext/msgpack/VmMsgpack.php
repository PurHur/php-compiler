<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * MessagePack encode/decode (php-src ext/msgpack/msgpack.c; #6551).
 *
 * PHP-in-PHP subset: null, bool, int, float, string, array/list, map.
 */
final class VmMsgpack
{
    private const INVALID_DATA = 'Invalid MessagePack data';

    public static function pack(Variable $value): string
    {
        return self::encodeValue($value->resolveIndirect());
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    public static function unpackWithOffset(string $data, int $offset = 0): array
    {
        if ($offset < 0 || $offset > \strlen($data)) {
            throw new MsgpackUnpackException(self::INVALID_DATA);
        }
        if ($offset === \strlen($data)) {
            throw new MsgpackUnpackException(self::INVALID_DATA);
        }

        return self::decodeAt($data, $offset);
    }

    public static function unpack(string $data, int $offset, ?Frame $frame)
    {
        try {
            [$value] = self::unpackWithOffset($data, $offset);

            return $value;
        } catch (MsgpackUnpackException) {
            self::emitWarning($frame, self::INVALID_DATA);

            return false;
        }
    }

    private static function encodeValue(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return "\xc0";
            case Variable::TYPE_BOOLEAN:
                return $value->toBool(null) ? "\xc3" : "\xc2";
            case Variable::TYPE_INTEGER:
                if ($value->isVmResource()) {
                    throw new \Exception('Cannot pack resource in msgpack');
                }

                return self::encodeInt($value->toInt(null));
            case Variable::TYPE_FLOAT:
                return "\xcb".pack('E', $value->toFloat(null));
            case Variable::TYPE_STRING:
                return self::encodeString($value->toString(null));
            case Variable::TYPE_ARRAY:
                return self::encodeArray($value->toArray());
            default:
                throw new \Exception('Cannot pack type '.$value->type.' in msgpack');
        }
    }

    private static function encodeInt(int $n): string
    {
        if ($n >= 0) {
            if ($n <= 0x7f) {
                return \chr($n);
            }
            if ($n <= 0xff) {
                return "\xcc".\chr($n);
            }
            if ($n <= 0xffff) {
                return "\xcd".pack('n', $n);
            }
            if ($n <= 0xffffffff) {
                return "\xce".pack('N', $n);
            }

            return "\xcf".pack('J', $n);
        }
        if ($n >= -0x20) {
            return \chr($n & 0xff);
        }
        if ($n >= -0x80) {
            return "\xd0".pack('c', $n);
        }
        if ($n >= -0x8000) {
            return "\xd1".pack('n', $n & 0xffff);
        }
        if ($n >= -0x80000000) {
            return "\xd2".pack('N', $n & 0xffffffff);
        }

        return "\xd3".pack('J', $n & 0xffffffffffffffff);
    }

    private static function encodeString(string $s): string
    {
        $len = \strlen($s);
        if ($len <= 0x1f) {
            return \chr(0xa0 | $len).$s;
        }
        if ($len <= 0xff) {
            return "\xd9".\chr($len).$s;
        }
        if ($len <= 0xffff) {
            return "\xda".pack('n', $len).$s;
        }

        return "\xdb".pack('N', $len).$s;
    }

    private static function encodeArray(HashTable $table): string
    {
        $pairs = iterator_to_array($table->iterateKeyed(true), false);
        $count = \count($pairs);
        if ($table->isPackedList()) {
            $out = self::encodeContainerHeader(0x90, 0xdc, 0xdd, $count);
            foreach ($pairs as [, $element]) {
                $out .= self::encodeValue($element);
            }

            return $out;
        }
        $out = self::encodeContainerHeader(0x80, 0xde, 0xdf, $count);
        foreach ($pairs as [$key, $element]) {
            $out .= self::encodeMapKey($key->resolveIndirect());
            $out .= self::encodeValue($element);
        }

        return $out;
    }

    private static function encodeContainerHeader(int $fixBase, int $tag16, int $tag32, int $count): string
    {
        if ($count <= 0x0f) {
            return \chr($fixBase | $count);
        }
        if ($count <= 0xffff) {
            return \chr($tag16).pack('n', $count);
        }

        return \chr($tag32).pack('N', $count);
    }

    private static function encodeMapKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type && !$key->isVmResource()) {
            return self::encodeInt($key->toInt(null));
        }
        if (Variable::TYPE_STRING === $key->type) {
            return self::encodeString($key->toString(null));
        }

        throw new \Exception('Cannot pack illegal array key type in msgpack');
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private static function decodeAt(string $data, int $offset): array
    {
        $len = \strlen($data);
        if ($offset >= $len) {
            throw new MsgpackUnpackException(self::INVALID_DATA);
        }
        $byte = \ord($data[$offset]);
        ++$offset;

        if ($byte <= 0x7f) {
            return [$byte, $offset];
        }
        if ($byte >= 0xe0) {
            return [$byte - 0x100, $offset];
        }
        if (0xc0 === $byte) {
            return [null, $offset];
        }
        if (0xc2 === $byte) {
            return [false, $offset];
        }
        if (0xc3 === $byte) {
            return [true, $offset];
        }
        if ($byte >= 0xa0 && $byte <= 0xbf) {
            return self::readString($data, $offset, $byte & 0x1f);
        }
        if ($byte >= 0x90 && $byte <= 0x9f) {
            return self::readArray($data, $offset, $byte & 0x0f);
        }
        if ($byte >= 0x80 && $byte <= 0x8f) {
            return self::readMap($data, $offset, $byte & 0x0f);
        }

        switch ($byte) {
            case 0xcc:
                return self::readUInt8($data, $offset);
            case 0xcd:
                return self::readUInt16($data, $offset);
            case 0xce:
                return self::readUInt32($data, $offset);
            case 0xcf:
                return self::readUInt64($data, $offset);
            case 0xd0:
                return self::readInt8($data, $offset);
            case 0xd1:
                return self::readInt16($data, $offset);
            case 0xd2:
                return self::readInt32($data, $offset);
            case 0xd3:
                return self::readInt64($data, $offset);
            case 0xca:
                return self::readFloat32($data, $offset);
            case 0xcb:
                return self::readFloat64($data, $offset);
            case 0xd9:
                return self::readStringWithLen($data, $offset, 1);
            case 0xda:
                return self::readStringWithLen($data, $offset, 2);
            case 0xdb:
                return self::readStringWithLen($data, $offset, 4);
            case 0xdc:
                return self::readArrayWithLen($data, $offset, 2);
            case 0xdd:
                return self::readArrayWithLen($data, $offset, 4);
            case 0xde:
                return self::readMapWithLen($data, $offset, 2);
            case 0xdf:
                return self::readMapWithLen($data, $offset, 4);
            default:
                throw new MsgpackUnpackException(self::INVALID_DATA);
        }
    }

    /** @return array{0: int, 1: int} */
    private static function readUInt8(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 1);

        return [\ord($data[$offset]), $offset + 1];
    }

    /** @return array{0: int, 1: int} */
    private static function readUInt16(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 2);
        $unpacked = unpack('n', $data, $offset);

        return [(int) $unpacked[1], $offset + 2];
    }

    /** @return array{0: int, 1: int} */
    private static function readUInt32(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 4);
        $unpacked = unpack('N', $data, $offset);

        return [(int) $unpacked[1], $offset + 4];
    }

    /** @return array{0: int, 1: int} */
    private static function readUInt64(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 8);
        $unpacked = unpack('J', $data, $offset);

        return [(int) $unpacked[1], $offset + 8];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt8(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 1);
        $unpacked = unpack('c', $data, $offset);

        return [(int) $unpacked[1], $offset + 1];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt16(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 2);
        $unpacked = unpack('n', $data, $offset);
        $value = (int) $unpacked[1];
        if ($value >= 0x8000) {
            $value -= 0x10000;
        }

        return [$value, $offset + 2];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt32(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 4);
        $unpacked = unpack('N', $data, $offset);
        $value = (int) $unpacked[1];
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }

        return [$value, $offset + 4];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt64(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 8);
        $unpacked = unpack('J', $data, $offset);
        $value = (int) $unpacked[1];
        if (\PHP_INT_SIZE < 8 && $value < 0) {
            throw new MsgpackUnpackException(self::INVALID_DATA);
        }

        return [$value, $offset + 8];
    }

    /** @return array{0: float, 1: int} */
    private static function readFloat32(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 4);
        $unpacked = unpack('G', $data, $offset);

        return [(float) $unpacked[1], $offset + 4];
    }

    /** @return array{0: float, 1: int} */
    private static function readFloat64(string $data, int $offset): array
    {
        self::requireBytes($data, $offset, 8);
        $unpacked = unpack('E', $data, $offset);

        return [(float) $unpacked[1], $offset + 8];
    }

    /** @return array{0: string, 1: int} */
    private static function readString(string $data, int $offset, int $length): array
    {
        self::requireBytes($data, $offset, $length);

        return [substr($data, $offset, $length), $offset + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readStringWithLen(string $data, int $offset, int $lenBytes): array
    {
        if (1 === $lenBytes) {
            [$length, $offset] = self::readUInt8($data, $offset);
        } elseif (2 === $lenBytes) {
            [$length, $offset] = self::readUInt16($data, $offset);
        } else {
            [$length, $offset] = self::readUInt32($data, $offset);
        }

        return self::readString($data, $offset, $length);
    }

    /** @return array{0: list<mixed>, 1: int} */
    private static function readArray(string $data, int $offset, int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            [$item, $offset] = self::decodeAt($data, $offset);
            $items[] = $item;
        }

        return [$items, $offset];
    }

    /** @return array{0: list<mixed>, 1: int} */
    private static function readArrayWithLen(string $data, int $offset, int $lenBytes): array
    {
        if (2 === $lenBytes) {
            [$count, $offset] = self::readUInt16($data, $offset);
        } else {
            [$count, $offset] = self::readUInt32($data, $offset);
        }

        return self::readArray($data, $offset, $count);
    }

    /** @return array{0: array<int|string, mixed>, 1: int} */
    private static function readMap(string $data, int $offset, int $count): array
    {
        $map = [];
        for ($i = 0; $i < $count; ++$i) {
            [$key, $offset] = self::decodeAt($data, $offset);
            if (!\is_int($key) && !\is_string($key)) {
                throw new MsgpackUnpackException(self::INVALID_DATA);
            }
            [$value, $offset] = self::decodeAt($data, $offset);
            $map[$key] = $value;
        }

        return [$map, $offset];
    }

    /** @return array{0: array<int|string, mixed>, 1: int} */
    private static function readMapWithLen(string $data, int $offset, int $lenBytes): array
    {
        if (2 === $lenBytes) {
            [$count, $offset] = self::readUInt16($data, $offset);
        } else {
            [$count, $offset] = self::readUInt32($data, $offset);
        }

        return self::readMap($data, $offset, $count);
    }

    private static function requireBytes(string $data, int $offset, int $need): void
    {
        if ($offset + $need > \strlen($data)) {
            throw new MsgpackUnpackException(self::INVALID_DATA);
        }
    }

    private static function emitWarning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

final class MsgpackUnpackException extends \RuntimeException
{
}
