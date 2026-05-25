<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM entry {@see __phpc_session_start_apply} — bool in caller {@see __value__} out-slot (#1882). */
final class SessionStart
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__phpc_session_start_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);
        $oneI8 = $i8->constInt(1, false);

        $entry = $fn->appendBasicBlock('ss_entry');
        $bbActive = $fn->appendBasicBlock('ss_active');
        $bbStart = $fn->appendBasicBlock('ss_start');
        $bbDone = $fn->appendBasicBlock('ss_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionName::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbActive, $bbStart);

        $context->builder->positionAtEnd($bbActive);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbStart);
        $context->builder->store($oneI8, SessionName::$activeGlobal);
        self::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function emitWriteBool(Context $context, Value $outPtr, bool $value): void
    {
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($outPtr, $valMap['type'])
        );
        $valueField = $context->builder->structGep($outPtr, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($i8->constInt($value ? 1 : 0, false), $firstByte);
    }
}
