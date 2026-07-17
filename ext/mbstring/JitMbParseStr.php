<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitParseStr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_parse_str() (#20015).
 *
 * Parse via {@see JitParseStr}. Returns raw i1 success (non-empty input).
 * Charset conversion + identify updates remain on the VM {@see VmMbParseStr} path.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_parse_str)
 */
final class JitMbParseStr
{
    public static function parse(Context $context, JITVariable $encoded, JITVariable $result): Value
    {
        JitParseStr::parse($context, $encoded, $result);

        $i1 = $context->getTypeFromString('int1');
        $literal = JitStringArg::compileTimeLiteral($encoded);
        if (null !== $literal) {
            return $i1->constInt('' !== $literal ? 1 : 0, false);
        }

        $encodedStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $encoded,
            'mb_parse_str',
            0,
            'string'
        );
        $len = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $encodedStr
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $len, $zero);
    }
}
