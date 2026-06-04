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

    private static ?bool $machineLe = null;

    private static function machineLe(): bool
    {
        if (null === self::$machineLe) {
            self::$machineLe = 0 !== \unpack('S', "\x00\x01")[1];
        }

        return self::$machineLe;
    }

    /**
     * @return array<int|string, int|string>|false
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
        $autoIdx = 1;

        foreach ($specs as $spec) {
            $code = $spec['code'];
            $arg = $spec['arg'];
            $need = self::needBytes($code, $arg);
            if (null === $need) {
                self::fail('unpack(): Type '.$code.': unknown format code');

                return false;
            }

            if ('X' === $code) {
                $pos = $arg > $pos ? 0 : $pos - $arg;
                continue;
            }
            if ('@' === $code) {
                $pos = $arg > 0 ? $arg : 0;
                continue;
            }
            if ('x' === $code) {
                if ($pos + $arg > $len) {
                    self::fail('unpack(): Type x: not enough input, need more bytes');

                    return false;
                }
                $pos += $arg;
                continue;
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

            switch ($code) {
                case 'a':
                case 'A':
                case 'Z':
                    $slen = 'Z' !== $code ? $arg : ($arg > 0 ? $arg - 1 : 0);
                    $val = \substr($data, $pos, $slen);
                    self::store($result, $spec, $autoIdx, $val);
                    $pos += $arg;
                    break;
                case 'h':
                case 'H':
                    $val = self::unpackHex($data, $pos, $arg, 'H' === $code);
                    self::store($result, $spec, $autoIdx, $val);
                    $pos += $need;
                    break;
                case 'c':
                case 'C':
                    for ($r = 0; $r < $arg; ++$r) {
                        $val = self::readLong($data, $pos, 1, self::machineLe(), 'c' === $code);
                        self::store($result, $spec, $autoIdx, $val);
                        ++$pos;
                    }
                    break;
                case 's':
                case 'S':
                case 'n':
                case 'v':
                    $le = self::machineLe();
                    $signed = 's' === $code;
                    if ('n' === $code) {
                        $le = false;
                    } elseif ('v' === $code) {
                        $le = true;
                    }
                    for ($r = 0; $r < $arg; ++$r) {
                        $val = self::readLong($data, $pos, 2, $le, $signed);
                        self::store($result, $spec, $autoIdx, $val);
                        $pos += 2;
                    }
                    break;
                case 'i':
                case 'I':
                    $size = \PHP_INT_SIZE;
                    for ($r = 0; $r < $arg; ++$r) {
                        $val = self::readLong($data, $pos, $size, self::machineLe(), 'i' === $code);
                        self::store($result, $spec, $autoIdx, $val);
                        $pos += $size;
                    }
                    break;
                case 'l':
                case 'L':
                case 'N':
                case 'V':
                    $le = self::machineLe();
                    $signed = 'l' === $code || 'L' === $code;
                    if ('N' === $code) {
                        $le = false;
                    } elseif ('V' === $code) {
                        $le = true;
                    }
                    for ($r = 0; $r < $arg; ++$r) {
                        $val = self::readLong($data, $pos, 4, $le, $signed);
                        self::store($result, $spec, $autoIdx, $val);
                        $pos += 4;
                    }
                    break;
                case 'q':
                case 'Q':
                case 'J':
                case 'P':
                    $le = self::machineLe();
                    $signed = 'q' === $code || 'Q' === $code;
                    if ('J' === $code) {
                        $le = false;
                    } elseif ('P' === $code) {
                        $le = true;
                    }
                    for ($r = 0; $r < $arg; ++$r) {
                        $val = self::readLong($data, $pos, 8, $le, $signed);
                        self::store($result, $spec, $autoIdx, $val);
                        $pos += 8;
                    }
                    break;
                default:
                    self::fail('unpack(): format not supported in this compiler build');

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

        while ($i < $flen && \count($specs) < self::MAX_SPECS) {
            if ('/' === $format[$i]) {
                ++$i;
            }
            if ($i >= $flen) {
                break;
            }
            $code = $format[$i++];
            $arg = 1;

            if ($i < $flen) {
                $c = $format[$i];
                if ('*' === $c) {
                    self::fail(\sprintf("unpack(): Type %s: '*' is not supported", $code));

                    return null;
                }
                if ($c >= '0' && $c <= '9') {
                    $arg = 0;
                    while ($i < $flen && $format[$i] >= '0' && $format[$i] <= '9') {
                        $arg = $arg * 10 + ((int) $format[$i] - (int) '0');
                        ++$i;
                    }
                }
            }

            $name = '';
            while ($i < $flen && '/' !== $format[$i] && !self::isCode($format[$i])) {
                if (\strlen($name) + 1 >= self::MAX_NAME) {
                    self::fail('unpack(): Argument #1 ($format) contains name longer than 64 characters');

                    return null;
                }
                $name .= $format[$i++];
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
        }

        return $specs;
    }

    public static function needBytes(string $code, int $arg): ?int
    {
        return match ($code) {
            'h', 'H' => (int) (($arg / 2) + ($arg % 2)),
            'a', 'A', 'Z', 'c', 'C', 'x' => $arg,
            's', 'S', 'n', 'v' => $arg * 2,
            'i', 'I' => $arg * \PHP_INT_SIZE,
            'l', 'L', 'N', 'V' => $arg * 4,
            'q', 'Q', 'J', 'P' => $arg * 8,
            'X', '@' => 0,
            default => null,
        };
    }

    private static function isCode(string $c): bool
    {
        return 1 === \strlen($c) && self::isSupportedCode($c);
    }

    private static function isSupportedCode(string $code): bool
    {
        return \in_array($code, [
            'a', 'A', 'Z', 'h', 'H', 'c', 'C', 's', 'S', 'i', 'I', 'l', 'L',
            'n', 'N', 'v', 'V', 'q', 'Q', 'J', 'P', 'x', 'X', '@',
        ], true);
    }

    /**
     * @param array<int|string, int|string> $result
     * @param array{code: string, arg: int, name: string, has_name: bool} $spec
     */
    private static function store(array &$result, array $spec, int &$autoIdx, int|string $val): void
    {
        if ($spec['has_name']) {
            $result[$spec['name']] = $val;
        } else {
            $result[$autoIdx++] = $val;
        }
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
