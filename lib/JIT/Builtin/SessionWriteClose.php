<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM entry {@see __phpc_session_write_close_apply} — bool in caller {@see __value__} out-slot (#1185). */
final class SessionWriteClose
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__phpc_session_write_close_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);

        $entry = $fn->appendBasicBlock('swc_entry');
        $bbInactive = $fn->appendBasicBlock('swc_inactive');
        $bbClose = $fn->appendBasicBlock('swc_close');
        $bbDone = $fn->appendBasicBlock('swc_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionName::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbClose, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbClose);
        $context->builder->store($zeroI8, SessionName::$activeGlobal);
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
