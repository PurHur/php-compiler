<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * String unary ~ for compiled JIT/AOT modules (#14823, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\VM\Variable::unaryOp()} TYPE_BITWISE_NOT on strings.
 * php-src: Zend/zend_operators.c
 */
final class StringBitwiseNotJitHelper
{
    public static function bitwiseNotArgv(string $string): string
    {
        $out = '';
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            $out .= \chr((~\ord($string[$i])) & 0xFF);
        }

        return $out;
    }
}
