<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pack() for nested JIT/AOT — native operands only (#13062).
 *
 * VM/enum paths stay in {@see PackEngine}; {@see PackJitHelper} uses this class.
 * php-src: ext/standard/pack.c — php_pack()
 */
final class PackJitEngine
{
    public const PACK_INT_SIZE = 4;

    private const MAX_SPECS = 256;

    private const MAX_OUT = 65536;

    /**
     * @param list<int|float|string|bool|null> $args
     */
    public static function pack(string $format, array $args): string
    {
        if ('' === $format) {
            return '';
        }

        $specs = self::parseFormat($format, $args);
        $outputSize = self::computeOutputSize($specs);
        if ($outputSize > self::MAX_OUT) {
            throw new \ValueError('integer overflow in format string');
        }

        $output = \str_repeat("\0", $outputSize > 0 ? $outputSize : 0);
        $outputPos = 0;
        $currentArg = 0;

        foreach ($specs as $spec) {
            [$output, $outputPos, $currentArg] = self::emitSpec(
                $spec['code'],
                $spec['arg'],
                $output,
                $outputPos,
                $args,
                $currentArg
            );
        }

        return \substr($output, 0, $outputPos);
    }

    /**
     * @param list<int|float|string|bool|null> $args
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function emitSpec(
        string $code,
        int $arg,
        string $output,
        int $outputPos,
        array $args,
        int $currentArg
    ): array {
        switch ($code) {
            case 'a':
            case 'A':
            case 'Z':
                $str = self::argString($args[$currentArg++]);
                $argCp = 'Z' !== $code ? $arg : ($arg > 0 ? $arg - 1 : 0);
                $pad = 'A' === $code ? ' ' : "\0";
                $chunk = \str_pad(\substr($str, 0, $argCp), $arg, $pad);
                $output = PackEngineEncode::writeAt($output, $outputPos, $chunk);

                return [$output, $outputPos + $arg, $currentArg];
            case 'h':
            case 'H':
                $str = self::argString($args[$currentArg++]);
                $packed = PackEngineEncode::packHex($str, $arg, 'H' === $code);
                $output = PackEngineEncode::writeAt($output, $outputPos, $packed);

                return [$output, $outputPos + \strlen($packed), $currentArg];
            case 'c':
            case 'C':
                return self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 1, PackEngineEncode::machineLe());
            case 's':
            case 'S':
            case 'n':
            case 'v':
                $le = PackEngineEncode::machineLe();
                if ('n' === $code) {
                    $le = false;
                } elseif ('v' === $code) {
                    $le = true;
                }

                return self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 2, $le);
            case 'i':
            case 'I':
                return self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, self::PACK_INT_SIZE, PackEngineEncode::machineLe());
            case 'l':
            case 'L':
            case 'N':
            case 'V':
                $le = PackEngineEncode::machineLe();
                if ('N' === $code) {
                    $le = false;
                } elseif ('V' === $code) {
                    $le = true;
                }

                return self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 4, $le);
            case 'q':
            case 'Q':
            case 'J':
            case 'P':
                $le = PackEngineEncode::machineLe();
                if ('J' === $code) {
                    $le = false;
                } elseif ('P' === $code) {
                    $le = true;
                }

                return self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 8, $le);
            case 'f':
                return self::emitFloatRepeat($output, $outputPos, $args, $currentArg, $arg, PackEngineEncode::machineLe());
            case 'g':
                return self::emitFloatRepeat($output, $outputPos, $args, $currentArg, $arg, true);
            case 'G':
                return self::emitFloatRepeat($output, $outputPos, $args, $currentArg, $arg, false);
            case 'd':
                return self::emitDoubleRepeat($output, $outputPos, $args, $currentArg, $arg, PackEngineEncode::machineLe());
            case 'e':
                return self::emitDoubleRepeat($output, $outputPos, $args, $currentArg, $arg, true);
            case 'E':
                return self::emitDoubleRepeat($output, $outputPos, $args, $currentArg, $arg, false);
            case 'x':
                $output = PackEngineEncode::writeAt($output, $outputPos, \str_repeat("\0", $arg));

                return [$output, $outputPos + $arg, $currentArg];
            case 'X':
                $outputPos = $arg > $outputPos ? 0 : $outputPos - $arg;

                return [$output, $outputPos, $currentArg];
            case '@':
                if ($arg > $outputPos) {
                    $output = PackEngineEncode::writeAt($output, $outputPos, \str_repeat("\0", $arg - $outputPos));
                }

                return [$output, $arg, $currentArg];
        }

        throw new \ValueError(\sprintf('Type %s: unknown format code', $code));
    }

    /**
     * @param list<int|float|string|bool|null> $args
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function emitLongRepeat(
        string $output,
        int $outputPos,
        array $args,
        int $currentArg,
        int $count,
        int $size,
        bool $le
    ): array {
        for ($r = 0; $r < $count; ++$r) {
            $output = PackEngineEncode::writeAt(
                $output,
                $outputPos,
                PackEngineEncode::putLong(self::takeArgLong($args, $currentArg), $size, $le)
            );
            $outputPos += $size;
        }

        return [$output, $outputPos, $currentArg];
    }

    /**
     * @param list<int|float|string|bool|null> $args
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function emitFloatRepeat(
        string $output,
        int $outputPos,
        array $args,
        int $currentArg,
        int $count,
        bool $le
    ): array {
        for ($r = 0; $r < $count; ++$r) {
            $output = PackEngineEncode::writeAt(
                $output,
                $outputPos,
                PackEngineEncode::putFloat(self::takeArgDouble($args, $currentArg), $le)
            );
            $outputPos += 4;
        }

        return [$output, $outputPos, $currentArg];
    }

    /**
     * @param list<int|float|string|bool|null> $args
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function emitDoubleRepeat(
        string $output,
        int $outputPos,
        array $args,
        int $currentArg,
        int $count,
        bool $le
    ): array {
        for ($r = 0; $r < $count; ++$r) {
            $output = PackEngineEncode::writeAt(
                $output,
                $outputPos,
                PackEngineEncode::putDouble(self::takeArgDouble($args, $currentArg), $le)
            );
            $outputPos += 8;
        }

        return [$output, $outputPos, $currentArg];
    }

    /**
     * @param list<int|float|string|bool|null> $args
     *
     * @return list<array{code: string, arg: int}>
     */
    private static function parseFormat(string $format, array $args): array
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
                        throw new \ValueError(\sprintf('Type %s: not enough arguments', $code));
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
                        throw new \ValueError(\sprintf('Type %s: too few arguments', $code));
                    }
                    break;
                default:
                    throw new \ValueError(\sprintf('Type %s: unknown format code', $code));
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
            switch ($code) {
                case 'h':
                case 'H':
                    $inc = (int) (($arg / 2) + ($arg % 2));
                    break;
                case 'a':
                case 'A':
                case 'Z':
                case 'c':
                case 'C':
                case 'x':
                    $inc = $arg;
                    break;
                case 's':
                case 'S':
                case 'n':
                case 'v':
                    $inc = $arg * 2;
                    break;
                case 'i':
                case 'I':
                    $inc = $arg * self::PACK_INT_SIZE;
                    break;
                case 'l':
                case 'L':
                case 'N':
                case 'V':
                    $inc = $arg * 4;
                    break;
                case 'q':
                case 'Q':
                case 'J':
                case 'P':
                    $inc = $arg * 8;
                    break;
                case 'f':
                case 'g':
                case 'G':
                    $inc = $arg * 4;
                    break;
                case 'd':
                case 'e':
                case 'E':
                    $inc = $arg * 8;
                    break;
                default:
                    $inc = 0;
                    break;
            }

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

    /** @param list<int|float|string|bool|null> $args */
    private static function takeArgLong(array $args, int &$currentArg): int
    {
        return self::argLong($args[$currentArg++]);
    }

    /** @param list<int|float|string|bool|null> $args */
    private static function takeArgDouble(array $args, int &$currentArg): float
    {
        return self::argDouble($args[$currentArg++]);
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
}
