<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pack() semantics in PHP (VM + reference for JIT lowering).
 *
 * php-src: ext/standard/pack.c — php_pack()
 * Replaces lib/AOT/runtime/phpc_pack.c (issue #5231).
 */
final class PackEngine
{
    private const MAX_SPECS = 256;
    private const MAX_OUT = 65536;

    private static ?bool $machineLe = null;

    private static function machineLe(): bool
    {
        if (null === self::$machineLe) {
            self::$machineLe = 0 !== \unpack('S', "\x00\x01")[1];
        }

        return self::$machineLe;
    }

    /**
     * @param list<int|float|string|bool|null> $args values after format string
     */
    public static function pack(string $format, array $args): string
    {
        if ('' === $format) {
            return '';
        }

        $specs = self::parseFormat($format, $args);
        if (null === $specs) {
            return '';
        }

        $outputSize = self::computeOutputSize($specs);
        if ($outputSize > self::MAX_OUT) {
            self::fail('pack(): integer overflow in format string');

            return '';
        }

        $output = \str_repeat("\0", $outputSize > 0 ? $outputSize : 0);
        $outputPos = 0;
        $currentArg = 0;

        foreach ($specs as $spec) {
            $code = $spec['code'];
            $arg = $spec['arg'];

            switch ($code) {
                case 'a':
                case 'A':
                case 'Z':
                    $str = self::argString($args[$currentArg++]);
                    $argCp = 'Z' !== $code ? $arg : ($arg > 0 ? $arg - 1 : 0);
                    $pad = 'A' === $code ? ' ' : "\0";
                    $chunk = \str_pad(\substr($str, 0, $argCp), $arg, $pad);
                    $output = self::writeAt($output, $outputPos, $chunk);
                    $outputPos += $arg;
                    break;
                case 'h':
                case 'H':
                    $str = self::argString($args[$currentArg++]);
                    $packed = self::packHex($str, $arg, 'H' === $code);
                    $output = self::writeAt($output, $outputPos, $packed);
                    $outputPos += \strlen($packed);
                    break;
                case 'c':
                case 'C':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putLong(self::argLong($args[$currentArg++]), 1, self::machineLe())
                        );
                        ++$outputPos;
                    }
                    break;
                case 's':
                case 'S':
                case 'n':
                case 'v':
                    $le = self::machineLe();
                    if ('n' === $code) {
                        $le = false;
                    } elseif ('v' === $code) {
                        $le = true;
                    }
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putLong(self::argLong($args[$currentArg++]), 2, $le)
                        );
                        $outputPos += 2;
                    }
                    break;
                case 'i':
                case 'I':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putLong(self::argLong($args[$currentArg++]), \PHP_INT_SIZE, self::machineLe())
                        );
                        $outputPos += \PHP_INT_SIZE;
                    }
                    break;
                case 'l':
                case 'L':
                case 'N':
                case 'V':
                    $le = self::machineLe();
                    if ('N' === $code) {
                        $le = false;
                    } elseif ('V' === $code) {
                        $le = true;
                    }
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putLong(self::argLong($args[$currentArg++]), 4, $le)
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'q':
                case 'Q':
                case 'J':
                case 'P':
                    $le = self::machineLe();
                    if ('J' === $code) {
                        $le = false;
                    } elseif ('P' === $code) {
                        $le = true;
                    }
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putLong(self::argLong($args[$currentArg++]), 8, $le)
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'f':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            \pack('f', (float) self::argDouble($args[$currentArg++]))
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'g':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putFloat((float) self::argDouble($args[$currentArg++]), true)
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'G':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putFloat((float) self::argDouble($args[$currentArg++]), false)
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'd':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            \pack('d', self::argDouble($args[$currentArg++]))
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'e':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putDouble(self::argDouble($args[$currentArg++]), true)
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'E':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putDouble(self::argDouble($args[$currentArg++]), false)
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'x':
                    $output = self::writeAt($output, $outputPos, \str_repeat("\0", $arg));
                    $outputPos += $arg;
                    break;
                case 'X':
                    $outputPos = $arg > $outputPos ? 0 : $outputPos - $arg;
                    break;
                case '@':
                    if ($arg > $outputPos) {
                        $output = self::writeAt($output, $outputPos, \str_repeat("\0", $arg - $outputPos));
                    }
                    $outputPos = $arg;
                    break;
            }
        }

        return \substr($output, 0, $outputPos);
    }

    /**
     * @param list<int|float|string|bool|null> $args
     *
     * @return list<array{code: string, arg: int}>|null
     */
    private static function parseFormat(string $format, array $args): ?array
    {
        $numArgs = \count($args);
        $specs = [];
        $formatLen = \strlen($format);
        $currentArg = 0;
        $i = 0;

        while ($i < $formatLen && \count($specs) < self::MAX_SPECS) {
            $code = $format[$i++];
            $arg = 1;

            if ($i < $formatLen) {
                $c = $format[$i];
                if ('*' === $c) {
                    $arg = -1;
                    ++$i;
                } elseif ($c >= '0' && $c <= '9') {
                    $arg = 0;
                    while ($i < $formatLen && $format[$i] >= '0' && $format[$i] <= '9') {
                        $arg = $arg * 10 + ((int) $format[$i] - (int) '0');
                        ++$i;
                    }
                }
            }

            switch ($code) {
                case 'x':
                case 'X':
                case '@':
                    if ($arg < 0) {
                        $arg = 1;
                    }
                    break;
                case 'a':
                case 'A':
                case 'Z':
                case 'h':
                case 'H':
                    if ($currentArg >= $numArgs) {
                        self::fail(\sprintf('pack(): Type %s: not enough arguments', $code));

                        return null;
                    }
                    if ($arg < 0) {
                        $str = self::argString($args[$currentArg] ?? '');
                        $arg = \strlen($str);
                        if ('Z' === $code) {
                            ++$arg;
                        }
                    }
                    ++$currentArg;
                    break;
                case 'c':
                case 'C':
                case 's':
                case 'S':
                case 'i':
                case 'I':
                case 'l':
                case 'L':
                case 'n':
                case 'N':
                case 'v':
                case 'V':
                case 'q':
                case 'Q':
                case 'J':
                case 'P':
                case 'f':
                case 'g':
                case 'G':
                case 'd':
                case 'e':
                case 'E':
                    if ($arg < 0) {
                        $arg = $numArgs - $currentArg;
                    }
                    $currentArg += $arg;
                    if ($currentArg > $numArgs) {
                        self::fail(\sprintf('pack(): Type %s: too few arguments', $code));

                        return null;
                    }
                    break;
                default:
                    self::fail(\sprintf('pack(): Type %s: unknown format code', $code));

                    return null;
            }

            $specs[] = ['code' => $code, 'arg' => $arg];
        }

        return $specs;
    }

    /**
     * @param list<array{code: string, arg: int}> $specs
     */
    private static function computeOutputSize(array $specs): int
    {
        $outputSize = 0;
        $outputPos = 0;

        foreach ($specs as $spec) {
            $code = $spec['code'];
            $arg = $spec['arg'];
            $inc = match ($code) {
                'h', 'H' => (int) (($arg / 2) + ($arg % 2)),
                'a', 'A', 'Z', 'c', 'C', 'x' => $arg,
                's', 'S', 'n', 'v' => $arg * 2,
                'i', 'I' => $arg * \PHP_INT_SIZE,
                'l', 'L', 'N', 'V' => $arg * 4,
                'q', 'Q', 'J', 'P' => $arg * 8,
                'f', 'g', 'G' => $arg * 4,
                'd', 'e', 'E' => $arg * 8,
                default => 0,
            };

            if ('X' === $code) {
                $outputPos = $arg > $outputPos ? 0 : $outputPos - $arg;
                continue;
            }
            if ('@' === $code) {
                $outputPos = $arg > 0 ? $arg : 0;
                if ($outputSize < $outputPos) {
                    $outputSize = $outputPos;
                }
                continue;
            }

            if ($inc > 0) {
                $outputPos += $inc;
            }
            if ($outputSize < $outputPos) {
                $outputSize = $outputPos;
            }
        }

        return $outputSize;
    }

    private static function packHex(string $str, int $arg, bool $highNibbleFirst): string
    {
        $out = '';
        $remain = $arg;
        $pos = 0;
        $slen = \strlen($str);
        $nibbleShift = $highNibbleFirst ? 4 : 0;
        $first = true;
        $byte = 0;

        if ($remain > $slen) {
            $remain = $slen;
        }

        while ($remain-- > 0) {
            $n = self::hexNibble($str[$pos++]);
            if ($n < 0) {
                $n = 0;
            }
            if ($first) {
                $byte = 0;
                $first = false;
            } else {
                $first = true;
            }
            $byte |= $n << $nibbleShift;
            $nibbleShift = ($nibbleShift + 4) & 7;
            if ($first) {
                $out .= \chr($byte);
            }
        }

        return $out;
    }

    private static function hexNibble(string $c): int
    {
        $o = \ord($c);
        if ($o >= 48 && $o <= 57) {
            return $o - 48;
        }
        if ($o >= 65 && $o <= 70) {
            return $o - 65 + 10;
        }
        if ($o >= 97 && $o <= 102) {
            return $o - 97 + 10;
        }

        return -1;
    }

    private static function putLong(int $value, int $size, bool $littleEndian): string
    {
        $fmt = match ($size) {
            1 => 'c',
            2 => 's',
            4 => 'l',
            8 => 'q',
            default => 'l',
        };
        $bytes = \pack($fmt, $value);

        if (\strlen($bytes) > $size) {
            $bytes = \substr($bytes, 0, $size);
        } elseif (\strlen($bytes) < $size) {
            $bytes = \str_pad($bytes, $size, "\0");
        }

        $needSwap = ($littleEndian !== self::machineLe());
        if (!$needSwap) {
            return $bytes;
        }

        return \strrev($bytes);
    }

    private static function putFloat(float $value, bool $littleEndian): string
    {
        $bytes = \pack('f', $value);
        if ($littleEndian !== self::machineLe()) {
            $bytes = \strrev($bytes);
        }

        return $bytes;
    }

    private static function putDouble(float $value, bool $littleEndian): string
    {
        $bytes = \pack('d', $value);
        if ($littleEndian !== self::machineLe()) {
            $bytes = \strrev($bytes);
        }

        return $bytes;
    }

    private static function writeAt(string $output, int $pos, string $chunk): string
    {
        $need = $pos + \strlen($chunk);
        if (\strlen($output) < $need) {
            $output .= \str_repeat("\0", $need - \strlen($output));
        }

        return \substr($output, 0, $pos).$chunk.\substr($output, $pos + \strlen($chunk));
    }

    private static function argString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '';
        }
        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    private static function argLong(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return (int) $value;
        }
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function argDouble(mixed $value): float
    {
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }
        if (\is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private static function fail(string $message): void
    {
        @\trigger_error($message, \E_USER_WARNING);
    }
}
