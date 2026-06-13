<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPLLVM\Builder;

/** LLVM entry {@see __phpc_session_unset_apply} — bool in caller {@see __value__} out-slot (#6261). */
final class SessionUnset
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__phpc_session_unset_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);

        $entry = $fn->appendBasicBlock('sun_entry');
        $bbInactive = $fn->appendBasicBlock('sun_inactive');
        $bbUnset = $fn->appendBasicBlock('sun_unset');
        $bbDone = $fn->appendBasicBlock('sun_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbUnset, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbUnset);
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
