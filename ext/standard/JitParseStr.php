<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/** LLVM lowering for parse_str() — ParseStrEngine at compile time, __compiler_parse_str at runtime (#6308). */
final class JitParseStr
{
    private const PARSE_DELIMITED_INTO_NATIVE = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseDelimitedIntoNative';

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

        $encodedStr = JitStringBuiltinArg::lowerZparamStr($context, $encoded, 'parse_str', 0, 'string');
        $parsedHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_parse_str'),
            $parsedHt,
            $encodedStr
        );
        ScopeBuiltinHelper::importHashtableIntoScope($context, $parsedHt);
    }

    public static function parse(
        Context $context,
        JITVariable $encoded,
        JITVariable $result,
        ?JITVariable $separator = null
    ): void {
        $delimiter = self::resolveDelimiter($context, $separator);
        $literal = JitStringArg::compileTimeLiteral($encoded);
        if (null !== $literal) {
            $parsedHt = JitParseStrMaterializer::materializeParsed(
                $context,
                ParseStrEngine::parse($literal, $delimiter)
            );
            $valPtr = JitValueBox::valuePtrFromVariable($context, $result);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $parsedHt
            );

            return;
        }

        $encodedStr = JitStringBuiltinArg::lowerZparamStr($context, $encoded, 'parse_str', 0, 'string');
        $parsedHt = HashTableHelper::alloc($context);
        self::emitRuntimeParse($context, $parsedHt, $encodedStr, $separator, $delimiter);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $result);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valPtr,
            $parsedHt
        );
    }

    private static function resolveDelimiter(Context $context, ?JITVariable $separator): string
    {
        if (null === $separator) {
            return '&';
        }

        $literal = JitStringArg::compileTimeLiteral($separator);

        return null !== $literal ? $literal : '&';
    }

    private static function emitRuntimeParse(
        Context $context,
        \PHPLLVM\Value $parsedHt,
        \PHPLLVM\Value $encodedStr,
        ?JITVariable $separator,
        string $compileTimeDelimiter
    ): void {
        if (null === $separator || '&' === $compileTimeDelimiter) {
            $context->builder->call(
                $context->lookupFunction('__compiler_parse_str'),
                $parsedHt,
                $encodedStr
            );

            return;
        }

        JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/ParseStrJitHelper.php',
            [self::PARSE_DELIMITED_INTO_NATIVE],
            '#17320'
        );
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_DELIMITED_INTO_NATIVE, '#17320');
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $parsedHt);
        $encodedArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $encodedStr,
            $helperFn->getParam(1)->typeOf()
        );
        $delimiterArg = JitStringBuiltinArg::lowerZparamStr($context, $separator, 'parse_str', 2, 'separator');
        $delimiterArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $delimiterArg,
            $helperFn->getParam(2)->typeOf()
        );
        $falseI32 = $context->getTypeFromString('int32')->constInt(0, false);
        $context->builder->call($helperFn, $destI64, $encodedArg, $delimiterArg, $falseI32);
    }
}
