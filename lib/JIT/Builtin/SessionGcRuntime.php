<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM session_gc() for JIT/AOT (issue #6006 phase 2).
 *
 * php-src: ext/session/session.c — php_session_gc
 */
final class SessionGcRuntime
{
    public static function ensureLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        SessionStorageRuntime::ensureLinked($context);

        self::implementIfMissing($context, '__phpc_session_gc_apply', self::emitGcApply(...));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($void, false, $valuePtr)
        );
    }

    private static function emitGcApply(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgc_apply_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI8 = $i8->constInt(0, false);
        $outPtr = $fn->getParam(0);
        $negOneI64 = $i64->constInt(-1, true);

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $bbInactive = BasicBlockHelper::append($context, 'sgc_inactive');
        $bbActive = BasicBlockHelper::append($context, 'sgc_active');
        $bbDone = BasicBlockHelper::append($context, 'sgc_done');
        $context->builder->branchIf($isActive, $bbActive, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        self::emitInactiveWarning($context);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbActive);
        $deleted = $context->builder->call($context->lookupFunction('phpc_session_gc_expired_files'));
        $failed = $context->builder->icmp(Builder::INT_EQ, $deleted, $negOneI64);
        $bbFail = BasicBlockHelper::append($context, 'sgc_fail');
        $bbOk = BasicBlockHelper::append($context, 'sgc_ok');
        $context->builder->branchIf($failed, $bbFail, $bbOk);

        $context->builder->positionAtEnd($bbFail);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $deleted
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitInactiveWarning(Context $context): void
    {
        $msg = 'session_gc(): Session cannot be garbage collected when there is no active session';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast(
            $context->constantFromString($msg),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $i8p->constNull(),
            $i32->constInt(0, false)
        );
    }
}
