<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrSplitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_str_split() (#26870 / #34278).
 *
 * Compile-time fold: {@see VmMbstring::strSplit} → packed HT.
 * Runtime: NestedJIT string peel (no HashTable under thin AOT — peer explode #27660)
 * then {@see JitExplode} in the user module.
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
            $length = JitStrictIntArg::lower($context, $args[1], 'mb_str_split', 2, 'length');
        }

        // NestedJIT helper compile can clear insert; restore before arg coerce/call (#34270).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrSplitRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbStrSplitRuntime::helperFunction($context),
            [$str, $length, $encPtr]
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::hashtableFromJoined($context, $joined);
    }

    /** Empty joined → []; otherwise explode on {@see MbStrSplitJitHelper::JOIN_DELIM}. */
    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mbss';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_str_split_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_str_split_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_str_split_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbStrSplitJitHelper::JOIN_DELIM)
        );
        $ownedJoined = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $joined
        );
        $ht = JitExplode::explode($context, $delim, $ownedJoined);
        $context->builder->store($ht, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
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
