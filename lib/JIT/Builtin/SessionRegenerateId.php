<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/** LLVM entry {@see __phpc_session_regenerate_id_apply} — bool in caller {@see __value__} out-slot (#1186). */
final class SessionRegenerateId
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__phpc_session_regenerate_id_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);

        $entry = $fn->appendBasicBlock('srid_entry');
        $bbInactive = $fn->appendBasicBlock('srid_inactive');
        $bbRotate = $fn->appendBasicBlock('srid_rotate');
        $bbDone = $fn->appendBasicBlock('srid_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionName::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbRotate, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbRotate);
        $context->builder->call($context->lookupFunction('__phpc_session_generate_new_id'));
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
