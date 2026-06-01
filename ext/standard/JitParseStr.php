<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/** LLVM lowering for parse_str() via __compiler_parse_str. */
final class JitParseStr
{
    /** One-arg parse_str(): populate compile-time locals from parsed query (issue #3708). */
    public static function parseIntoScope(Context $context, JITVariable $encoded): void
    {
        $encodedStr = JitStringArg::lower($context, $encoded, 'parse_str() argument #1');
        $parsedHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_parse_str'),
            $parsedHt,
            $encodedStr
        );
        ScopeBuiltinHelper::importHashtableIntoScope($context, $parsedHt);
    }

    public static function parse(Context $context, JITVariable $encoded, JITVariable $result): void
    {
        $encodedStr = JitStringArg::lower($context, $encoded, 'parse_str() argument #1');
        $parsedHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_parse_str'),
            $parsedHt,
            $encodedStr
        );
        $valPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $result);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valPtr,
            $parsedHt
        );
    }
}
