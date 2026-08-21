<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrGetcsv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for fgetcsv() — compose {@see JitFgets} + {@see JitStrGetcsv} (#1192, #6750, #33334).
 *
 * Do not call `__compiler_fgetcsv` (StringFgetcsvJit NestedJIT bridge): thin AOT SIGSEGVs
 * after c:main_before_php when that ABI NestedJITs CSV helpers mid-function (#33334 / re-#27180).
 * Peer: {@see JitStrGetcsv} avoids StreamCsv aggregate ensureLinked for the same reason (#27069).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fgetcsv)
 */
final class JitFgetcsv
{
    /** @return Value (__value__* — array row, or boolean false on EOF/failure) */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $lengthLong,
        Value $separatorStr,
        Value $enclosureStr,
        Value $escapeStr,
    ): Value {
        // Link CSV NestedJIT helpers before emitting into the caller (main). Doing
        // ensureLinked inside JitStrGetcsv after fgets IR is live still NestedJITs, but
        // pinning first keeps StreamRead + CSV ABIs stable (#33334).
        StringStrGetcsv::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        // php-src: length <= 0 means no limit; fgets ABI uses length as max (incl. NUL).
        $defaultCap = $i64->constInt(8192, false);
        $useDefault = $context->builder->icmp(Builder::INT_SLT, $lengthLong, $i64->constInt(1, false));
        $fgetsLen = $context->builder->select($useDefault, $defaultCap, $lengthLong);

        $lineBox = JitFgets::invoke($context, $handleLong, $fgetsLen);
        $linePtr = JitValueBox::pointer($context, $lineBox);

        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($linePtr, $map['type']));
        $i8 = $context->getTypeFromString('int8');
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );

        $eofBb = BasicBlockHelper::append($context, 'fgetcsv_compose_eof');
        $parseBb = BasicBlockHelper::append($context, 'fgetcsv_compose_parse');
        $doneBb = BasicBlockHelper::append($context, 'fgetcsv_compose_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isBool, $eofBb, $parseBb);

        $context->builder->positionAtEnd($eofBb);
        // JitFgets writes bool false on EOF — propagate.
        $context->builder->store($linePtr, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($parseBb);
        $lineStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $linePtr
        );
        // Own the fgets buffer before NestedJIT str_getcsv — shared/temporary
        // __string__* from readString UAF'd under thin AOT (#33334).
        $lineOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $lineStr
        );
        $csvBox = JitStrGetcsv::invoke(
            $context,
            $lineOwned,
            $separatorStr,
            $enclosureStr,
            $escapeStr
        );
        $context->builder->store(JitValueBox::pointer($context, $csvBox), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }
}
