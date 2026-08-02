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
            // php-src pack.c — leftover argc after empty format still warns (#22687).
            self::warnUnusedArguments(0, \count($args));

            return '';
        }

        $specs = self::parseFormat($format, $args);
        $outputSize = self::computeOutputSize($specs);
        if ($outputSize > self::MAX_OUT) {
            throw new \ValueError('integer overflow in format string');
        }

        $output = PackEngineEncode::zeros($outputSize > 0 ? $outputSize : 0);
        $outputPos = 0;
        $currentArg = 0;

        foreach ($specs as $spec) {
            // By-ref emit — NestedJIT list-assign from array returns drops updates (#22990).
            self::emitSpec(
                $spec['code'],
                $spec['arg'],
                $output,
                $outputPos,
                $args,
                $currentArg
            );
        }

        // php-src ext/standard/pack.c — php_error_docref "%d arguments unused" (#22687).
        self::warnUnusedArguments($currentArg, \count($args));

        return \substr($output, 0, $outputPos);
    }

    /** php-src pack.c leftover argc → warning text (nested JIT has no Frame; cf. UnpackEngine::fail). */
    private static function warnUnusedArguments(int $consumed, int $argc): void
    {
        if ($consumed >= $argc) {
            return;
        }
        @\trigger_error('pack(): '.($argc - $consumed).' arguments unused', \E_USER_WARNING);
    }

    /**
     * @param list<int|float|string|bool|null> $args
     */
    private static function emitSpec(
        string $code,
        int $arg,
        string &$output,
        int &$outputPos,
        array $args,
        int &$currentArg
    ): void {
        switch ($code) {
            case 'a':
            case 'A':
            case 'Z':
                $valueIdx = $currentArg;
                $str = self::argString($args[$currentArg++], $valueIdx);
                $argCp = 'Z' !== $code ? $arg : ($arg > 0 ? $arg - 1 : 0);
                $pad = 'A' === $code ? ' ' : "\0";
                $chunk = PackEngineEncode::padRight(\substr($str, 0, $argCp), $arg, $pad);
                $output = PackEngineEncode::writeAt($output, $outputPos, $chunk);
                $outputPos = $outputPos + $arg;

                return;
            case 'h':
            case 'H':
                $valueIdx = $currentArg;
                $str = self::argString($args[$currentArg++], $valueIdx);
                $packed = PackEngineEncode::packHex($str, $arg, 'H' === $code);
                $output = PackEngineEncode::writeAt($output, $outputPos, $packed);
                $outputPos = $outputPos + \strlen($packed);

                return;
            case 'c':
            case 'C':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 1, PackEngineEncode::machineLe());

                return;
            // Split endian cases — NestedJIT bool literal assigns in array-return paths
            // used to emit `ret i1` (#22990); keep void + direct bool args.
            case 'n':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 2, false);

                return;
            case 'v':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 2, true);

                return;
            case 's':
            case 'S':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 2, PackEngineEncode::machineLe());

                return;
            case 'i':
            case 'I':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, self::PACK_INT_SIZE, PackEngineEncode::machineLe());

                return;
            case 'N':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 4, false);

                return;
            case 'V':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 4, true);

                return;
            case 'l':
            case 'L':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 4, PackEngineEncode::machineLe());

                return;
            case 'J':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 8, false);

                return;
            case 'P':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 8, true);

                return;
            case 'q':
            case 'Q':
                self::emitLongRepeat($output, $outputPos, $args, $currentArg, $arg, 8, PackEngineEncode::machineLe());

                return;
            case 'f':
                self::emitFloatRepeat($output, $outputPos, $args, $currentArg, $arg, PackEngineEncode::machineLe());

                return;
            case 'g':
                self::emitFloatRepeat($output, $outputPos, $args, $currentArg, $arg, true);

                return;
            case 'G':
                self::emitFloatRepeat($output, $outputPos, $args, $currentArg, $arg, false);

                return;
            case 'd':
                self::emitDoubleRepeat($output, $outputPos, $args, $currentArg, $arg, PackEngineEncode::machineLe());

                return;
            case 'e':
                self::emitDoubleRepeat($output, $outputPos, $args, $currentArg, $arg, true);

                return;
            case 'E':
                self::emitDoubleRepeat($output, $outputPos, $args, $currentArg, $arg, false);

                return;
            case 'x':
                $output = PackEngineEncode::writeAt($output, $outputPos, PackEngineEncode::zeros($arg));
                $outputPos = $outputPos + $arg;

                return;
            case 'X':
                if ($arg > $outputPos) {
                    $outputPos = $outputPos - $outputPos;
                } else {
                    $outputPos = $outputPos - $arg;
                }

                return;
            case '@':
                if ($arg > $outputPos) {
                    $output = PackEngineEncode::writeAt($output, $outputPos, PackEngineEncode::zeros($arg - $outputPos));
                }
                $outputPos = $arg;

                return;
        }

        throw new \ValueError('Type '.$code.': unknown format code');
    }

    /**
     * @param list<int|float|string|bool|null> $args
     */
    private static function emitLongRepeat(
        string &$output,
        int &$outputPos,
        array $args,
        int &$currentArg,
        int $count,
        int $size,
        bool $le
    ): void {
        for ($r = 0; $r < $count; ++$r) {
            $output = PackEngineEncode::writeAt(
                $output,
                $outputPos,
                PackEngineEncode::putLong(self::takeArgLong($args, $currentArg), $size, $le)
            );
            $outputPos = $outputPos + $size;
        }
    }

    /**
     * @param list<int|float|string|bool|null> $args
     */
    private static function emitFloatRepeat(
        string &$output,
        int &$outputPos,
        array $args,
        int &$currentArg,
        int $count,
        bool $le
    ): void {
        for ($r = 0; $r < $count; ++$r) {
            $output = PackEngineEncode::writeAt(
                $output,
                $outputPos,
                Ieee754::encodeFloat32(self::takeArgDouble($args, $currentArg), $le)
            );
            $outputPos = $outputPos + 4;
        }
    }

    /**
     * @param list<int|float|string|bool|null> $args
     */
    private static function emitDoubleRepeat(
        string &$output,
        int &$outputPos,
        array $args,
        int &$currentArg,
        int $count,
        bool $le
    ): void {
        for ($r = 0; $r < $count; ++$r) {
            $output = PackEngineEncode::writeAt(
                $output,
                $outputPos,
                Ieee754::encodeFloat64(self::takeArgDouble($args, $currentArg), $le)
            );
            $outputPos = $outputPos + 8;
        }
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
                        $str = self::argString($args[$currentArg] ?? '', $currentArg);
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
        $idx = $currentArg++;

        return self::argLong($args[$idx], $idx);
    }

    /** @param list<int|float|string|bool|null> $args */
    private static function takeArgDouble(array $args, int &$currentArg): float
    {
        $idx = $currentArg++;

        return self::argDouble($args[$idx], $idx);
    }

    private static function argString(mixed $value, int $valueArgIndex = 0): string
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
            self::rejectNullForwardProfileValue($valueArgIndex);

            return '';
        }

        return (string) $value;
    }

    private static function argLong(mixed $value, int $valueArgIndex = 0): int
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
        if (null === $value) {
            self::rejectNullForwardProfileValue($valueArgIndex);

            return 0;
        }
        if (\is_string($value) && self::stringLooksNumeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function argDouble(mixed $value, int $valueArgIndex = 0): float
    {
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }
        if (\is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (null === $value) {
            self::rejectNullForwardProfileValue($valueArgIndex);

            return 0.0;
        }
        if (\is_string($value) && self::stringLooksNumeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Lean numeric-string check for NestedJIT (#22981).
     *
     * Avoid host {@see is_numeric()} — its JIT lowering pulls stream-filter ABI
     * that helper-unit emit does not link (`__compiler_is_stream_filter_resource`).
     */
    private static function stringLooksNumeric(string $value): bool
    {
        $len = \strlen($value);
        if (0 === $len) {
            return false;
        }
        $i = 0;
        if ('+' === $value[0] || '-' === $value[0]) {
            if (1 === $len) {
                return false;
            }
            $i = 1;
        }
        $sawDigit = false;
        $sawDot = false;
        for (; $i < $len; ++$i) {
            $c = $value[$i];
            if ($c >= '0' && $c <= '9') {
                $sawDigit = true;
                continue;
            }
            if ('.' === $c && !$sawDot) {
                $sawDot = true;
                continue;
            }
            if (('e' === $c || 'E' === $c) && $sawDigit) {
                ++$i;
                if ($i < $len && ('+' === $value[$i] || '-' === $value[$i])) {
                    ++$i;
                }
                if ($i >= $len) {
                    return false;
                }
                for (; $i < $len; ++$i) {
                    if ($value[$i] < '0' || $value[$i] > '9') {
                        return false;
                    }
                }

                return true;
            }

            return false;
        }

        return $sawDigit;
    }

    /** php-src ext/standard/pack.c — null value operands TypeError on 8.4 forward profile (#18992, #19388). */
    private static function rejectNullForwardProfileValue(int $valueArgIndex): void
    {
        if (VmString::requiresZparamStrStrictNullOnForwardProfile()) {
            throw new \TypeError(\sprintf(
                'pack(): Argument #%d ($values) must be of type string, null given',
                $valueArgIndex + 2
            ));
        }
    }
}
