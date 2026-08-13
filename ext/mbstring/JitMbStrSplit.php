<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrSplitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_str_split() via MbStrSplitJitHelper NestedJIT (#26870).
 *
 * Compile-time fold: {@see VmMbstring::strSplit} → packed HT.
 * Runtime: direct helper call (peer {@see JitMbStrcut} / #4573).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 */
final class JitMbStrSplit
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity checked by mb_str_split::call via requireArgCountRangeJit (#30786).
        $argc = \count($args);

        $encoding = 'UTF-8';
        if ($argc >= 3) {
            if (JITVariable::TYPE_STRING !== $args[2]->type) {
                throw new \LogicException(
                    'mb_str_split() encoding must be a string literal in this compiler build'
                );
            }
            $encoding = $args[2]->compileTimeString ?? null;
            if (null === $encoding) {
                throw new \LogicException(
                    'mb_str_split() encoding must be a string literal in this compiler build'
                );
            }
        }
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_str_split() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }

        $stringLit = $args[0]->compileTimeString ?? null;
        $lengthLit = 1;
        $lengthIsLiteral = true;
        if ($argc >= 2) {
            $resolved = self::compileTimeLong($context, $args[1]);
            if (null === $resolved) {
                $lengthIsLiteral = false;
            } else {
                $lengthLit = $resolved;
            }
        }
        if (null !== $stringLit && $lengthIsLiteral && $lengthLit > 0) {
            return self::foldLiteral($context, $stringLit, $lengthLit, $encoding);
        }

        // Soft-null DEP+coerce on 8.4 (peer mb_strcut / #24207).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_str_split',
            0,
            'string'
        );
        $i64 = $context->getTypeFromString('int64');
        $length = $i64->constInt(1, false);
        if ($argc >= 2) {
            $length = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[1],
                'mb_str_split',
                2,
                'length'
            );
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrSplitRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbStrSplitRuntime::helperFunction($context),
            [$str, $length, $encPtr]
        );

        return JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
    }

    private static function foldLiteral(
        Context $context,
        string $literal,
        int $length,
        string $encoding
    ): Value {
        $parts = VmMbstring::strSplit($literal, $length, $encoding);

        return self::buildHtFromStringParts($context, $parts);
    }

    /** @param list<string> $parts */
    private static function buildHtFromStringParts(Context $context, array $parts): Value
    {
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($parts as $i => $part) {
            $slice = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($part))
            );
            $context->builder->call(
                $setString,
                $ht,
                $sizeT->constInt($i, false),
                $slice
            );
        }

        return $ht;
    }

    private static function compileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
    }
}
