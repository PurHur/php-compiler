<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbSplitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_split() (#34391 leftover of #13367).
 *
 * NestedJIT joined string (no interned return — #27181) then {@see JitExplode}
 * in the user module without {@see __string__separate} (SIGSEGV on NestedJIT strings).
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_split)
 */
final class JitMbSplit
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_split',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'mb_split',
            1,
            'string'
        );
        $i64 = $context->getTypeFromString('int64');
        $limit = $i64->constInt(-1, true);
        if (\count($args) >= 3) {
            $limit = JitStrictIntArg::lower($context, $args[2], 'mb_split', 3, 'limit');
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSplitRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSplitRuntime::helperFunction($context),
            [$pattern, $string, $limit]
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::hashtableFromJoined($context, $joined);
    }

    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mbsplit';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_split_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_split_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_split_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbSplitJitHelper::JOIN_DELIM)
        );
        $ht = JitExplode::explode(
            $context,
            $delim,
            $joined,
            $i64->constInt(-1, true)
        );
        $context->builder->store($ht, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }
}
