<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPLLVM\Builder;

/** LLVM entry {@see __phpc_session_destroy_apply} — bool in caller {@see __value__} out-slot (#1182). */
final class SessionDestroy
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__phpc_session_destroy_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zeroI8 = $i8->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);

        $entry = $fn->appendBasicBlock('sd_entry');
        $bbInactive = $fn->appendBasicBlock('sd_inactive');
        $bbDestroy = $fn->appendBasicBlock('sd_destroy');
        $bbDone = $fn->appendBasicBlock('sd_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionName::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbDestroy, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDestroy);
        $context->builder->store($zeroI8, SessionName::$activeGlobal);
        $context->builder->store($zeroI64, SessionId::$lenGlobal);
        $empty = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            $context->builder->store($empty, SuperglobalInit::$globals['_SESSION']);
        }
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
