<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_pad() for compiled JIT/AOT modules (#14863, php-in-PHP).
 *
 * Semantics match VmString strPad SSOT for VM. The pad loop is inlined here so
 * NestedJIT/AOT does not call into VmString — that path returned null and
 * segfaulted user-script AOT (peer #23204 / #23911).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_pad)
 */
final class StrPadJitHelper
{
    public static function padArgv(string $input, int $padLength, string $padString, int $padType): string
    {
        $inputLen = self::byteLength($input);
        if ($padLength <= 0 || $padLength <= $inputLen) {
            return $input;
        }
        if ('' === $padString) {
            // php-src string.c — keep literal (no VmString call; NestedJIT isolation) (#29292)
            throw new \ValueError('str_pad(): Argument #3 ($pad_string) must not be empty');
        }
        $need = $padLength - $inputLen;
        if (2 === $padType) {
            $leftNeed = intdiv($need, 2);
            $rightNeed = $need - $leftNeed;

            return self::repeatPad($padString, $leftNeed).$input.self::repeatPad($padString, $rightNeed);
        }
        $padding = self::repeatPad($padString, $need);
        if (0 === $padType) {
            return $padding.$input;
        }

        return $input.$padding;
    }

    private static function byteLength(string $string): int
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }

        return $len;
    }

    private static function repeatPad(string $padString, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $padLen = self::byteLength($padString);
        if ($padLen <= 0) {
            return '';
        }
        $fullCopies = intdiv($length, $padLen);
        $remainder = $length % $padLen;
        $padding = '';
        for ($i = 0; $i < $fullCopies; ++$i) {
            $padding .= $padString;
        }
        for ($i = 0; $i < $remainder; ++$i) {
            $padding .= $padString[$i];
        }

        return $padding;
    }
}
