<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * unpack() semantics in PHP (VM + reference for JIT lowering).
 *
 * php-src: ext/standard/pack.c — php_unpack()
 * Replaces lib/AOT/runtime/phpc_unpack.c (issue #5442).
 */
final class UnpackEngine
{
    private const MAX_SPECS = 256;
    private const MAX_NAME = 64;

    /** php-src pack.c: repetitions = -1 for '*' in unpack(). */
    private const STAR_ARG = -1;

    private static ?bool $machineLe = null;

    private static function machineLe(): bool
    {
        if (null === self::$machineLe) {
            self::$machineLe = 0 !== \unpack('S', "\x00\x01")[1];
        }

        return self::$machineLe;
    }

    /**
     * @return array<int|string, int|float|string>|false
     */
    public static function unpack(string $format, string $data, int $offset = 0): array|false
    {
        $specs = self::parseFormat($format);
        if (null === $specs) {
            return false;
        }
        $len = \strlen($data);
        if ($offset < 0 || $offset > $len) {
            self::fail('unpack(): Argument #3 ($offset) must be contained in argument #2 ($data)');

            return false;
        }
        $pos = $offset;
        $result = [];

        foreach ($specs as $spec) {
            $code = $spec['code'];
            $arg = $spec['arg'];
            $isStar = self::STAR_ARG === $arg;
            $remaining = $len - $pos;

            if ('X' === $code) {
                if ($isStar) {
                    self::fail("unpack(): Type X: '*' ignored");
                    $arg = 1;
                }
                $pos = $arg > $pos ? 0 : $pos - $arg;
                continue;
            }
            if ('@' === $code) {
                $pos = $arg > 0 ? $arg : 0;
                continue;
            }
            if ('x' === $code) {
                if ($isStar) {
                    $arg = $remaining;
                }
                if ($pos + $arg > $len) {
                    self::fail('unpack(): Type x: not enough input, need more bytes');

                    return false;
                }
                $pos += $arg;
                continue;
            }

            if ($isStar && \in_array($code, ['a', 'A', 'Z', 'h', 'H'], true)) {
                if (!self::unpackStarString($result, $spec, $code, $data, $pos, $remaining)) {
                    return false;
                }
                continue;
            }

            if ($isStar) {
                $unit = self::unitBytes($code);
                if (null === $unit) {
                    self::fail('unpack(): format not supported in this compiler build');

                    return false;
                }
                $repIdx = 0;
                while ($pos + $unit <= $len) {
                    if (!self::unpackOne($result, $spec, $repIdx, $code, $data, $pos, $len)) {
                        return false;
                    }
                    ++$repIdx;
                }
                continue;
            }

            // php-src pack.c: repeat-count types check shortage per element in unpackOne().
            if (!(self::argIsRepeatCount($code) && $arg > 1)) {
                $need = self::needBytes($code, $arg);
                if (null === $need) {
                    self::fail('unpack(): Type '.$code.': unknown format code');

                    return false;
                }

                if ($pos + $need > $len) {
                    self::fail(\sprintf(
                        'unpack(): Type %s: not enough input, need %d, have %d',
                        $code,
                        $need,
                        $len - $pos
                    ));

                    return false;
                }
            } else {
                $need = self::needBytes($code, $arg);
                if (null === $need) {
                    self::fail('unpack(): Type '.$code.': unknown format code');

                    return false;
                }
            }

            if (!self::unpackFixed($result, $spec, $code, $arg, $data, $pos, $need ?? 0)) {
                return false;
            }
        }

        return $result;
    }

    /**
     * @return list<array{code: string, arg: int, name: string, has_name: bool}>|null
     */
    public static function parseFormat(string $format): ?array
    {
        $specs = [];
        $flen = \strlen($format);
        $i = 0;

        // php-src ext/standard/pack.c PHP_FUNCTION(unpack): names run until '/' only.
        while ($i < $flen && \count($specs) < self::MAX_SPECS) {
            $code = $format[$i++];
            $arg = 1;

            if ($i < $flen) {
                $c = $format[$i];
                if ('*' === $c) {
                    $arg = self::STAR_ARG;
                    ++$i;
                } elseif ($c >= '0' && $c <= '9') {
                    $arg = 0;
                    while ($i < $flen && $format[$i] >= '0' && $format[$i] <= '9') {
                        $arg = $arg * 10 + ((int) $format[$i] - (int) '0');
                        ++$i;
                    }
                }
            }

            $nameStart = $i;
            while ($i < $flen && '/' !== $format[$i]) {
                ++$i;
            }
            $name = \substr($format, $nameStart, $i - $nameStart);
            if (\strlen($name) >= self::MAX_NAME) {
                self::fail('unpack(): Argument #1 ($format) contains name longer than 64 characters');

                return null;
            }

            if (!self::isSupportedCode($code)) {
                self::fail(\sprintf('unpack(): Type %s: unknown format code', $code));

                return null;
            }

            $specs[] = [
                'code' => $code,
                'arg' => $arg,
                'name' => $name,
                'has_name' => '' !== $name,
            ];

            if ($i < $flen && '/' === $format[$i]) {
                ++$i;
            }
        }

        return $specs;
    }

    public static function needBytes(string $code, int $arg): ?int
    {
        return match ($code) {
            'h', 'H' => (int) (($arg / 2) + ($arg % 2)),
            'a', 'A', 'Z', 'c', 'C', 'x' => $arg,
            's', 'S', 'n', 'v' => $arg * 2,
            'i', 'I' => $arg * PackEngine::PACK_INT_SIZE,
            'l', 'L', 'N', 'V' => $arg * 4,
            'q', 'Q', 'J', 'P' => $arg * 8,
            'f', 'g', 'G' => $arg * 4,
            'd', 'e', 'E' => $arg * 8,
            'X', '@' => 0,
            default => null,
        };
    }

    private static function isSupportedCode(string $code): bool
    {
        return \in_array($code, [
            'a', 'A', 'Z', 'h', 'H', 'c', 'C', 's', 'S', 'i', 'I', 'l', 'L',
            'n', 'N', 'v', 'V', 'q', 'Q', 'J', 'P', 'f', 'g', 'G', 'd', 'e', 'E',
            'x', 'X', '@',
        ], true);
    }

    /**
     * php-src pack.c: $arg is a repeat count for numeric/ieee types, byte/nibble length for a/A/Z/h/H.
     */
    private static function argIsRepeatCount(string $code): bool
    {
        return !\in_array($code, ['a', 'A', 'Z', 'h', 'H'], true);
    }

    /**
     * @param array<int|string, int|float|string> $result
     * @param array{code: string, arg: int, name: string, has_name: bool} $spec
     */
    private static function store(array &$result, array $spec, int $repIdx, int|float|string $val): void
    {
        if ($spec['has_name']) {
            $key = $spec['name'];
            if ($spec['arg'] > 1 && self::argIsRepeatCount($spec['code'])) {
                $key .= (string) ($repIdx + 1);
            }
            $result[$key] = $val;

            return;
        }
        // php-src: unnamed keys are 1-based per format segment; later segments overwrite.
        $result[$repIdx + 1] = $val;
    }

    private static function unitBytes(string $code): ?int
    {
        return match ($code) {
            'c', 'C' => 1,
            's', 'S', 'n', 'v' => 2,
            'i', 'I' => PackEngine::PACK_INT_SIZE,
            'l', 'L', 'N', 'V' => 4,
            'q', 'Q', 'J', 'P' => 8,
            'f', 'g', 'G' => 4,
            'd', 'e', 'E' => 8,
            default => null,
        };
    }

    /**
     * @param array<int|string, int|string> $result
     * @param array{code: string, arg: int, name: string, has_name: bool} $spec
     */
    private static function unpackStarString(
        array &$result,
        array $spec,
        string $code,
        string $data,
        int &$pos,
        int $remaining
    ): bool {
        switch ($code) {
            case 'a':
                $val = \substr($data, $pos, $remaining);
                self::store($result, $spec, 0, $val);
                $pos += $remaining;
                break;
            case 'A':
                $val = \substr($data, $pos, $remaining);
                while ('' !== $val && \in_array($val[-1], ["\0", ' ', "\t", "\r", "\n"], true)) {
                    $val = \substr($val, 0, -1);
                }
                self::store($result, $spec, 0, $val);
                $pos += $remaining;
                break;
            case 'Z':
                $zlen = $remaining;
                for ($s = 0; $s < $remaining; ++$s) {
                    if ("\0" === $data[$pos + $s]) {
                        $zlen = $s;
                        break;
                    }
                }
                self::store($result, $spec, 0, \substr($data, $pos, $zlen));
                $pos += $remaining;
                break;
            case 'h':
            case 'H':
                $hexArg = $remaining * 2;
                self::store(
                    $result,
                    $spec,
                    0,
                    self::unpackHex($data, $pos, $hexArg, 'H' === $code)
                );
                $pos += $remaining;
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * @param array<int|string, int|float|string> $result
     * @param array{code: string, arg: int, name: string, has_name: bool} $spec
     */
    private static function unpackOne(
        array &$result,
        array $spec,
        int $repIdx,
        string $code,
        string $data,
        int &$pos,
        int $dataLen
    ): bool {
        $unit = self::unitBytes($code);
        if (null === $unit) {
            self::fail('unpack(): format not supported in this compiler build');

            return false;
        }

        if ($pos + $unit > $dataLen) {
            self::fail(\sprintf(
                'unpack(): Type %s: not enough input, need %d, have %d',
                $code,
                $unit,
                $dataLen - $pos
            ));

            return false;
        }

        if (self::isIeeeCode($code)) {
            self::store($result, $spec, $repIdx, self::readIeee($data, $pos, $code));
            $pos += $unit;

            return true;
        }

        [$le, $signed] = self::endianSigned($code);
        $val = self::readLong($data, $pos, $unit, $le, $signed);
        self::store($result, $spec, $repIdx, $val);
        $pos += $unit;

        return true;
    }

    /**
     * @param array<int|string, int|string> $result
     * @param array{code: string, arg: int, name: string, has_name: bool} $spec
     */
    private static function unpackFixed(
        array &$result,
        array $spec,
        string $code,
        int $arg,
        string $data,
        int &$pos,
        int $need
    ): bool {
        $dataLen = \strlen($data);
        switch ($code) {
            case 'a':
            case 'A':
                $val = \substr($data, $pos, $arg);
                self::store($result, $spec, 0, $val);
                $pos += $arg;
                break;
            case 'Z':
                $zlen = $arg;
                for ($s = 0; $s < $arg; ++$s) {
                    if ("\0" === $data[$pos + $s]) {
                        $zlen = $s;
                        break;
                    }
                }
                self::store($result, $spec, 0, \substr($data, $pos, $zlen));
                $pos += $arg;
                break;
            case 'h':
            case 'H':
                self::store(
                    $result,
                    $spec,
                    0,
                    self::unpackHex($data, $pos, $arg, 'H' === $code)
                );
                $pos += $need;
                break;
            case 'c':
            case 'C':
                for ($r = 0; $r < $arg; ++$r) {
                    if (!self::unpackOne($result, $spec, $r, $code, $data, $pos, $dataLen)) {
                        return false;
                    }
                }
                break;
            case 's':
            case 'S':
            case 'n':
            case 'v':
            case 'i':
            case 'I':
            case 'l':
            case 'L':
            case 'N':
            case 'V':
            case 'q':
            case 'Q':
            case 'J':
            case 'P':
                for ($r = 0; $r < $arg; ++$r) {
                    if (!self::unpackOne($result, $spec, $r, $code, $data, $pos, $dataLen)) {
                        return false;
                    }
                }
                break;
            case 'f':
            case 'g':
            case 'G':
            case 'd':
            case 'e':
            case 'E':
                for ($r = 0; $r < $arg; ++$r) {
                    if (!self::unpackOne($result, $spec, $r, $code, $data, $pos, $dataLen)) {
                        return false;
                    }
                }
                break;
            default:
                self::fail('unpack(): format not supported in this compiler build');

                return false;
        }

        return true;
    }

    private static function isIeeeCode(string $code): bool
    {
        return \in_array($code, ['f', 'g', 'G', 'd', 'e', 'E'], true);
    }

    /** @return bool|null machine-endian when null */
    private static function ieeeLittleEndian(string $code): ?bool
    {
        return match ($code) {
            'f', 'd' => null,
            'g', 'e' => true,
            'G', 'E' => false,
            default => null,
        };
    }

    private static function readIeee(string $data, int $pos, string $code): float
    {
        $size = \in_array($code, ['f', 'g', 'G'], true) ? 4 : 8;
        $slice = \substr($data, $pos, $size);
        $le = self::ieeeLittleEndian($code);
        $little = null === $le ? self::machineLe() : $le;

        return 4 === $size
            ? Ieee754::decodeFloat32($slice, $little)
            : Ieee754::decodeFloat64($slice, $little);
    }

    /** @return array{0: bool, 1: bool} little-endian, signed */
    private static function endianSigned(string $code): array
    {
        $le = self::machineLe();
        $signed = \in_array($code, ['c', 's', 'i', 'l', 'q'], true);
        if ('n' === $code || 'N' === $code || 'J' === $code) {
            $le = false;
        } elseif ('v' === $code || 'V' === $code || 'P' === $code) {
            $le = true;
        } elseif (\in_array($code, ['C', 'S', 'I', 'L', 'Q'], true)) {
            $signed = false;
        }

        return [$le, $signed];
    }

    private static function unpackHex(string $data, int $pos, int $arg, bool $highNibbleFirst): string
    {
        $buf = '';
        for ($n = 0; $n < $arg; ++$n) {
            $bi = (int) ($n / 2);
            $b = \ord($data[$pos + $bi]);
            $nibble = $highNibbleFirst
                ? (0 === ($n & 1) ? (($b >> 4) & 0xF) : ($b & 0xF))
                : (0 === ($n & 1) ? ($b & 0xF) : (($b >> 4) & 0xF));
            $buf .= $nibble < 10 ? \chr(48 + $nibble) : \chr(97 + $nibble - 10);
        }

        return $buf;
    }

    private static function readLong(string $data, int $pos, int $size, bool $littleEndian, bool $signed): int
    {
        $slice = \substr($data, $pos, $size);
        if ('' === $slice) {
            return 0;
        }
        if (1 === $size) {
            return $signed ? \unpack('c', $slice)[1] : \unpack('C', $slice)[1];
        }
        if (2 === $size && $littleEndian === self::machineLe()) {
            return $signed ? \unpack('s', $slice)[1] : \unpack('S', $slice)[1];
        }
        if (4 === $size && $littleEndian === self::machineLe()) {
            return $signed ? \unpack('l', $slice)[1] : \unpack('L', $slice)[1];
        }
        if (2 === $size) {
            $u = \unpack($littleEndian ? 'v' : 'n', $slice)[1];

            return $signed && $u > 0x7FFF ? $u - 0x10000 : $u;
        }
        if (4 === $size) {
            $u = \unpack($littleEndian ? 'V' : 'N', $slice)[1];

            return $signed && $u > 0x7FFFFFFF ? $u - 0x100000000 : $u;
        }
        if (8 === $size) {
            return \unpack($littleEndian ? 'P' : 'J', $slice)[1];
        }

        return 0;
    }

    private static function fail(string $message): void
    {
        @\trigger_error($message, \E_USER_WARNING);
    }
}
