<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pack() for compiled JIT/AOT modules via PackEngine PHP (#9133, php-in-PHP).
 *
 * SSOT: {@see PackJitEngine} (JIT/AOT) and {@see PackEngine} (VM).
 * php-src: ext/standard/pack.c — php_pack()
 */
final class PackJitHelper
{
    private const TAG_NULL = 0;

    private const TAG_LONG = 1;

    private const TAG_DOUBLE = 2;

    private const TAG_BOOL = 3;

    private const TAG_STRING = 4;

    private const TAG_ARRAY = 5;

    /** NestedJIT rejects function-static null defaults (#2286); class prop is OK (#22842). */
    private static ?PackedArgvArrayMarker $arrayMarker = null;

    /** Internal marker decoded from {@see self::TAG_ARRAY} argv slots (#13598). */
    public static function packedArrayMarker(): PackedArgvArrayMarker
    {
        // Explicit null check — ??= / isset on typed static props breaks NestedJIT (#22842 / #2286).
        if (null === self::$arrayMarker) {
            self::$arrayMarker = new PackedArgvArrayMarker();
        }

        return self::$arrayMarker;
    }

    /**
     * @param string $packedArgs length-prefixed argv blob from LLVM bridge
     */
    public static function packArgv(string $format, string $packedArgs): string
    {
        return PackJitEngine::pack($format, self::unpackArgv($packedArgs));
    }

    /**
     * Decode argv blob from {@see PackArgvSerialize} for other JIT helpers (#9131).
     *
     * @return list<int|float|bool|string|null>
     */
    public static function decodePackedArgv(string $packed): array
    {
        return self::unpackArgv($packed);
    }

    /**
     * @return list<int|float|bool|string|null>
     */
    private static function unpackArgv(string $packed): array
    {
        $args = [];
        $len = \strlen($packed);
        $pos = 0;
        while ($pos < $len) {
            $tag = \ord($packed[$pos++]);
            switch ($tag) {
                case self::TAG_NULL:
                    $args[] = null;
                    break;
                case self::TAG_LONG:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    // Manual LE int64 — avoid \unpack (NestedJIT cycle / missing ABI — #22981).
                    $args[] = self::readInt64Le($packed, $pos);
                    $pos += 8;
                    break;
                case self::TAG_DOUBLE:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = Ieee754::decodeFloat64(\substr($packed, $pos, 8), true);
                    $pos += 8;
                    break;
                case self::TAG_BOOL:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = 0 !== self::readInt64Le($packed, $pos);
                    $pos += 8;
                    break;
                case self::TAG_STRING:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $sl = self::readInt64Le($packed, $pos);
                    $pos += 8;
                    if ($sl < 0 || $pos + $sl > $len) {
                        break 2;
                    }
                    $args[] = \substr($packed, $pos, $sl);
                    $pos += $sl;
                    break;
                case self::TAG_ARRAY:
                    $args[] = self::packedArrayMarker();
                    break;
                default:
                    break 2;
            }
        }

        return $args;
    }

    /** Little-endian signed int64 from {@see PackArgvSerialize} blob (#22981). */
    private static function readInt64Le(string $bytes, int $pos): int
    {
        $lo = (\ord($bytes[$pos])
            | (\ord($bytes[$pos + 1]) << 8)
            | (\ord($bytes[$pos + 2]) << 16)
            | (\ord($bytes[$pos + 3]) << 24)) & 0xFFFFFFFF;
        $hi = (\ord($bytes[$pos + 4])
            | (\ord($bytes[$pos + 5]) << 8)
            | (\ord($bytes[$pos + 6]) << 16)
            | (\ord($bytes[$pos + 7]) << 24)) & 0xFFFFFFFF;

        return ($hi << 32) | $lo;
    }
}

/** @internal argv blob marker for array operands in sprintf JIT bridge (#13598). */
final class PackedArgvArrayMarker
{
}
