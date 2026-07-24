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
                    $args[] = (int) \unpack('q', \substr($packed, $pos, 8))[1];
                    $pos += 8;
                    break;
                case self::TAG_DOUBLE:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = \unpack('d', \substr($packed, $pos, 8))[1];
                    $pos += 8;
                    break;
                case self::TAG_BOOL:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $args[] = 0 !== (int) \unpack('q', \substr($packed, $pos, 8))[1];
                    $pos += 8;
                    break;
                case self::TAG_STRING:
                    if ($pos + 8 > $len) {
                        break 2;
                    }
                    $sl = (int) \unpack('q', \substr($packed, $pos, 8))[1];
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
}

/** @internal argv blob marker for array operands in sprintf JIT bridge (#13598). */
final class PackedArgvArrayMarker
{
}
