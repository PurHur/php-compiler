<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * Session start helpers + LLVM entry {@see __phpc_session_start_apply} (#1882, #21564).
 *
 * Honest apply body lives in {@see \PHPCompiler\ext\standard\JitSessionLifecycleKernel}
 * for embed + standalone (no legacy C-symbol forwarder hop).
 */
final class SessionStart
{
    /** Bodies always emitted by {@see \PHPCompiler\ext\standard\JitSessionLifecycleKernel} (#21564). */
    public static function implement(Context $context): void
    {
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

    /**
     * Emit E_WARNING when session_start() runs after headers_sent (php-src ext/session/session.c).
     *
     * {@see Context::constantFromString} yields a C-string global ([N x i8]*), not __string__* —
     * structGep on that receiver produced DestTy Trunc / invalid bitcasts under 005 AOT (#1974).
     */
    public static function emitHeadersSentWarning(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $message = VmSession::HEADERS_SENT_START_WARNING;
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
