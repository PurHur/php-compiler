<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * String bitwise helpers for compiled JIT/AOT modules (#14823, #32431, php-in-PHP).
 *
 * Unary ~: {@see \PHPCompiler\VM\Variable::unaryOp()} TYPE_BITWISE_NOT.
 * Binary &|^: Zend bitwise_*_function string/string (byte-wise; not convert_to_long).
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

    /** AND length = min (zend_binary_zval_bitwise_and_function). */
    public static function bitwiseAndArgv(string $left, string $right): string
    {
        $len = \strlen($left);
        $rlen = \strlen($right);
        if ($rlen < $len) {
            $len = $rlen;
        }
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= \chr((\ord($left[$i]) & \ord($right[$i])) & 0xFF);
        }

        return $out;
    }

    /** XOR length = min (zend_binary_zval_bitwise_xor_function). */
    public static function bitwiseXorArgv(string $left, string $right): string
    {
        $len = \strlen($left);
        $rlen = \strlen($right);
        if ($rlen < $len) {
            $len = $rlen;
        }
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= \chr((\ord($left[$i]) ^ \ord($right[$i])) & 0xFF);
        }

        return $out;
    }

    /**
     * OR length = max; tail copied from the longer string
     * (zend_binary_zval_bitwise_or_function).
     */
    public static function bitwiseOrArgv(string $left, string $right): string
    {
        $llen = \strlen($left);
        $rlen = \strlen($right);
        $min = $llen < $rlen ? $llen : $rlen;
        $out = '';
        for ($i = 0; $i < $min; ++$i) {
            $out .= \chr((\ord($left[$i]) | \ord($right[$i])) & 0xFF);
        }
        if ($llen > $rlen) {
            $out .= \substr($left, $min);
        } elseif ($rlen > $llen) {
            $out .= \substr($right, $min);
        }

        return $out;
    }
}
