<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/** LLVM lowering for parse_str() — ParseStrEngine at compile time, __compiler_parse_str at runtime (#6308). */
final class JitParseStr
{
    /** One-arg parse_str(): populate compile-time locals from parsed query (issue #3708). */
    public static function parseIntoScope(Context $context, JITVariable $encoded): void
    {
        $literal = JitStringArg::compileTimeLiteral($encoded);
        if (null !== $literal) {
            $parsedHt = JitParseStrMaterializer::materializeParsed(
                $context,
                ParseStrEngine::parse($literal)
            );
            ScopeBuiltinHelper::importHashtableIntoScope($context, $parsedHt);

            return;
        }

        $encodedStr = JitStringBuiltinArg::lower($context, $encoded, 'parse_str', 0, 'string');
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
        $literal = JitStringArg::compileTimeLiteral($encoded);
        if (null !== $literal) {
            $parsedHt = JitParseStrMaterializer::materializeParsed(
                $context,
                ParseStrEngine::parse($literal)
            );
            $valPtr = JitValueBox::valuePtrFromVariable($context, $result);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $parsedHt
            );

            return;
        }

        $encodedStr = JitStringBuiltinArg::lower($context, $encoded, 'parse_str', 0, 'string');
        $parsedHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_parse_str'),
            $parsedHt,
            $encodedStr
        );
        $valPtr = JitValueBox::valuePtrFromVariable($context, $result);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valPtr,
            $parsedHt
        );
    }
}
