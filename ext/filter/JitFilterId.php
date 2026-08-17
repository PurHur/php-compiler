<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\JitClassExists;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for filter_id() (php-src ext/filter/filter.c; #3485). */
final class JitFilterId
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            return self::boxedId($context, FilterConstants::idForName($literal));
        }

        $nameStr = JitStringBuiltinArg::lower($context, $nameArg, 'filter_id', 0, 'name');
        $nameData = JitClassExists::stringDataPtr($context, $nameStr);

        return self::lookupRuntime($context, $nameData);
    }

    private static function boxedId(Context $context, ?int $id): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (null === $id) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        } else {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($id, false)
            );
        }

        return $ptr;
    }

    private static function lookupRuntime(Context $context, Value $nameData): Value
    {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        // __compiler_strcasecmp after LibcExtern always-on drop (#31787).
        $strcasecmpFn = $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP);
        $id = (string) (++self::$blockSerial);
        $doneBlock = BasicBlockHelper::append($context, 'filter_id_done_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'filter_id_fail_'.$id);

        $tailBlock = $context->builder->getInsertBlock();
        $names = array_keys(FilterConstants::NAME_TO_ID);
        $firstTest = BasicBlockHelper::append($context, 'filter_id_entry_'.$id);
        $context->builder->positionAtEnd($tailBlock);
        $context->builder->branch($firstTest);

        $prevContinue = $firstTest;
        $matchBlocks = [];
        foreach ($names as $index => $name) {
            $filterId = FilterConstants::NAME_TO_ID[$name];
            $testBlock = $prevContinue;
            $context->builder->positionAtEnd($testBlock);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $candidateData = JitClassExists::stringDataPtr($context, $candidate);
            $cmp = $context->builder->call($strcasecmpFn, $nameData, $candidateData);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $matchBlock = BasicBlockHelper::append($context, 'filter_id_hit_'.$id.'_'.$name);
            $isLast = $index === \count($names) - 1;
            $continueBlock = $isLast ? $failBlock : BasicBlockHelper::append($context, 'filter_id_try_'.$id.'_'.$name);
            $context->builder->branchIf($isMatch, $matchBlock, $continueBlock);

            $context->builder->positionAtEnd($matchBlock);
            $matchSlot = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $matchSlot, $i64->constInt($filterId, false));
            $matchPtr = JitValueBox::pointer($context, $matchSlot);
            $matchBlocks[] = [$matchBlock, $matchPtr];
            $context->builder->branch($doneBlock);

            $prevContinue = $continueBlock;
        }

        $context->builder->positionAtEnd($failBlock);
        $failSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $failSlot, $context->constantFromBool(false));
        $failPtr = JitValueBox::pointer($context, $failSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($failPtr->typeOf());
        foreach ($matchBlocks as [$block, $ptr]) {
            $phi->addIncoming($ptr, $block);
        }
        $phi->addIncoming($failPtr, $failBlock);

        return $phi;
    }
}
