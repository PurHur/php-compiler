<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * pack() semantics in PHP (VM + reference for JIT lowering).
 *
 * php-src: ext/standard/pack.c — php_pack()
 * Replaces lib/AOT/runtime/phpc_pack.c (issue #5231).
 */
final class PackEngine
{
    /** php-src ext/standard/pack.c: 'i'/'I' use sizeof(int), 4 on all supported PHP platforms. */
    public const PACK_INT_SIZE = 4;

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
     * @param list<int|float|string|bool|null|Variable> $args values after format string
     */
    public static function pack(string $format, array $args, ?Frame $frame = null): string
    {
        if ('' === $format) {
            return '';
        }

        $specs = self::parseFormat($format, $args, $frame);

        $outputSize = self::computeOutputSize($specs);
        if ($outputSize > self::MAX_OUT) {
            throw new \ValueError('integer overflow in format string');
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
                    $str = self::argString($args[$currentArg++], $frame);
                    $argCp = 'Z' !== $code ? $arg : ($arg > 0 ? $arg - 1 : 0);
                    $pad = 'A' === $code ? ' ' : "\0";
                    $chunk = \str_pad(\substr($str, 0, $argCp), $arg, $pad);
                    $output = self::writeAt($output, $outputPos, $chunk);
                    $outputPos += $arg;
                    break;
                case 'h':
                case 'H':
                    $str = self::argString($args[$currentArg++], $frame);
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
                            self::putLong(self::takeArgLong($args, $currentArg, $frame), 1, self::machineLe())
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
                            self::putLong(self::takeArgLong($args, $currentArg, $frame), 2, $le)
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
                            self::putLong(self::takeArgLong($args, $currentArg, $frame), self::PACK_INT_SIZE, self::machineLe())
                        );
                        $outputPos += self::PACK_INT_SIZE;
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
                            self::putLong(self::takeArgLong($args, $currentArg, $frame), 4, $le)
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
                            self::putLong(self::takeArgLong($args, $currentArg, $frame), 8, $le)
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'f':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putFloat((float) self::takeArgDouble($args, $currentArg, $frame), self::machineLe())
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'g':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putFloat((float) self::takeArgDouble($args, $currentArg, $frame), true)
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'G':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putFloat((float) self::takeArgDouble($args, $currentArg, $frame), false)
                        );
                        $outputPos += 4;
                    }
                    break;
                case 'd':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putDouble(self::takeArgDouble($args, $currentArg, $frame), self::machineLe())
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'e':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putDouble(self::takeArgDouble($args, $currentArg, $frame), true)
                        );
                        $outputPos += 8;
                    }
                    break;
                case 'E':
                    for ($r = 0; $r < $arg; ++$r) {
                        $output = self::writeAt(
                            $output,
                            $outputPos,
                            self::putDouble(self::takeArgDouble($args, $currentArg, $frame), false)
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
     * Operand kinds consumed by $format value args (php-src ext/standard/pack.c; #8816 JIT guard).
     *
     * @return list<'string'|'int'|'float'>
     */
    public static function valueOperandKinds(string $format, int $numValueArgs): array
    {
        if ('' === $format || 0 === $numValueArgs) {
            return [];
        }

        $specs = self::parseFormat($format, array_fill(0, $numValueArgs, 0), null);
        $kinds = [];
        foreach ($specs as $spec) {
            $code = $spec['code'];
            $arg = $spec['arg'];
            if (\in_array($code, ['a', 'A', 'Z', 'h', 'H'], true)) {
                $kinds[] = 'string';
                continue;
            }
            $kind = \in_array($code, ['f', 'g', 'G', 'd', 'e', 'E'], true) ? 'float' : 'int';
            for ($r = 0; $r < $arg; ++$r) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * @param list<int|float|string|bool|null|Variable> $args
     *
     * @return list<array{code: string, arg: int}>
     */
    private static function parseFormat(string $format, array $args, ?Frame $frame = null): array
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
                        $str = self::argString($args[$currentArg] ?? '', $frame);
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

        // php-src ext/standard/pack.c: odd nibble count emits high/low nibble as one byte (#12217).
        if (!$first) {
            $out .= \chr($byte);
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
        switch ($size) {
            case 1:
                $fmt = 'c';
                break;
            case 2:
                $fmt = 's';
                break;
            case 8:
                $fmt = 'q';
                break;
            case 4:
            default:
                $fmt = 'l';
                break;
        }
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
        return Ieee754::encodeFloat32($value, $littleEndian);
    }

    private static function putDouble(float $value, bool $littleEndian): string
    {
        return Ieee754::encodeFloat64($value, $littleEndian);
    }

    private static function writeAt(string $output, int $pos, string $chunk): string
    {
        $need = $pos + \strlen($chunk);
        if (\strlen($output) < $need) {
            $output .= \str_repeat("\0", $need - \strlen($output));
        }

        return \substr($output, 0, $pos).$chunk.\substr($output, $pos + \strlen($chunk));
    }

    /**
     * @param list<int|float|string|bool|null|Variable> $args
     */
    private static function takeArgLong(array $args, int &$currentArg, ?Frame $frame): int
    {
        $idx = $currentArg++;

        return self::argLong($args[$idx], $frame, $idx);
    }

    /**
     * @param list<int|float|string|bool|null|Variable> $args
     */
    private static function takeArgDouble(array $args, int &$currentArg, ?Frame $frame): float
    {
        $idx = $currentArg++;

        return self::argDouble($args[$idx], $frame, $idx);
    }

    private static function argString(mixed $value, ?Frame $frame = null): string
    {
        if ($value instanceof Variable) {
            $resolved = $value->resolveIndirect();
            EnumCaseSupport::packRejectStringOperand($resolved);
            if (Variable::TYPE_STRING === $resolved->type) {
                return $resolved->toString();
            }
            if (Variable::TYPE_INTEGER === $resolved->type) {
                return (string) $resolved->toInt();
            }
            if (Variable::TYPE_FLOAT === $resolved->type) {
                return (string) $resolved->toFloat();
            }
            if (Variable::TYPE_BOOLEAN === $resolved->type) {
                return $resolved->toBool() ? '1' : '';
            }
            if (Variable::TYPE_NULL === $resolved->type) {
                return '';
            }

            throw new \LogicException('pack() string format requires a string operand in this compiler build');
        }
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

    private static function argLong(mixed $value, ?Frame $frame = null, int $valueArgIndex = 0): int
    {
        if ($value instanceof Variable) {
            $resolved = $value->resolveIndirect();
            EnumCaseSupport::packRejectNumericOperand($resolved, $valueArgIndex, 'int');
            $enumLong = EnumCaseSupport::packCoerceToLong($resolved, $frame?->vmContext, $frame);
            if (null !== $enumLong) {
                return $enumLong;
            }
            if (Variable::TYPE_INTEGER === $resolved->type) {
                return $resolved->toInt();
            }
            if (Variable::TYPE_FLOAT === $resolved->type) {
                return (int) $resolved->toFloat();
            }
            if (Variable::TYPE_BOOLEAN === $resolved->type) {
                return $resolved->toBool() ? 1 : 0;
            }
            if (Variable::TYPE_NULL === $resolved->type) {
                return 0;
            }
            if (Variable::TYPE_STRING === $resolved->type && is_numeric($resolved->toString())) {
                return (int) $resolved->toString();
            }

            return 0;
        }
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

    private static function argDouble(mixed $value, ?Frame $frame = null, int $valueArgIndex = 0): float
    {
        if ($value instanceof Variable) {
            $resolved = $value->resolveIndirect();
            EnumCaseSupport::packRejectNumericOperand($resolved, $valueArgIndex, 'float');
            $enumDouble = EnumCaseSupport::packCoerceToDouble($resolved, $frame?->vmContext, $frame);
            if (null !== $enumDouble) {
                return $enumDouble;
            }
            if (Variable::TYPE_FLOAT === $resolved->type) {
                return $resolved->toFloat();
            }
            if (Variable::TYPE_INTEGER === $resolved->type) {
                return (float) $resolved->toInt();
            }
            if (Variable::TYPE_BOOLEAN === $resolved->type) {
                return $resolved->toBool() ? 1.0 : 0.0;
            }
            if (Variable::TYPE_NULL === $resolved->type) {
                return 0.0;
            }
            if (Variable::TYPE_STRING === $resolved->type && is_numeric($resolved->toString())) {
                return (float) $resolved->toString();
            }

            return 0.0;
        }
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
