<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStreamCsv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for fgetcsv() via StringFgetcsvJit (#1192, #6750; ensureLinked #33189).
 *
 * Type no longer always-declares `__compiler_fgetcsv` after the leftover always-on shell
 * drop (#33189) — must ensureLinked before lookup (peer {@see JitStreamGetContents} #33178).
 */
final class JitFgetcsv
{
    /** @return Value
     * (array row, or boolean false on failure/EOF) */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $lengthLong,
        Value $separatorStr,
        Value $enclosureStr,
        Value $escapeStr,
    ): Value {
        // #33189 — Type dropped always-on __compiler_fgetcsv; link before lookup.
        StringStreamCsv::ensureLinked($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $row = $context->builder->call(
            $context->lookupFunction('__compiler_fgetcsv'),
            $handleLong,
            $lengthLong,
            $separatorStr,
            $enclosureStr,
            $escapeStr
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $row, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fgetcsv_fail');
        $okBlock = BasicBlockHelper::append($context, 'fgetcsv_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fgetcsv_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $row);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
