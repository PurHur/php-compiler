<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/** LLVM entry {@see __phpc_session_abort_apply} — bool in caller {@see __value__} out-slot (#6002). */
final class SessionAbort
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__phpc_session_abort_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);

        $entry = $fn->appendBasicBlock('sab_entry');
        $bbInactive = $fn->appendBasicBlock('sab_inactive');
        $bbAbort = $fn->appendBasicBlock('sab_abort');
        $bbDone = $fn->appendBasicBlock('sab_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbAbort, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbAbort);
        $context->builder->store($zeroI8, SessionStorageGlobals::$activeGlobal);
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
