<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for undefined $GLOBALS['name'] E_WARNING (#17482).
 *
 * SSOT: {@see \PHPCompiler\VM\UndefinedGlobalVariableJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class UndefinedGlobalVariableRuntime
{
    private const ABI_EMIT_WARNING = '__undefined_global_var__emitWarning';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $triggerProbe = $context->module->getNamedFunction('__compiler_trigger_error');
        if (null === $triggerProbe || 0 === $triggerProbe->countBasicBlocks()) {
            StringTriggerErrorJit::implement($context);
        }
        self::implementEmitWarningBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitWarningForName(Context $context, string $name): void
    {
        if ('' === $name) {
            return;
        }
        self::ensureLinked($context);
        $message = \PHPCompiler\VM\UndefinedGlobalVariableJitHelper::warningMessage($name);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $lineVal = $i32->constInt(max(0, $context->callSiteLine), false);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $lineVal
        );
    }

    private static function implementEmitWarningBridge(Context $context): void
    {
        $abiName = self::ABI_EMIT_WARNING;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        // Module-local memcpy(3) after LibcExtern always-on drop (#31885).
        \PHPCompiler\JIT\LibcExtern::ensureMemcpyDecl($context);
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('undefined_global_var_warn_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $namePtr = $fn->getParam(0);
        $nameLen = $fn->getParam(1);
        self::emitCallHelperWithCstr($context, $fn, $namePtr, $nameLen);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function emitCallHelperWithCstr(
        Context $context,
        LlvmFunction $fn,
        \PHPLLVM\Value $namePtr,
        \PHPLLVM\Value $nameLen
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $bufSize = 128;
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'undef_global_var_name');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $copyLen = $context->builder->select(
            $context->builder->icmp(
                \PHPLLVM\Builder::INT_UGE,
                $nameLen,
                $sizeT->constInt($bufSize - 1, false)
            ),
            $sizeT->constInt($bufSize - 1, false),
            $nameLen
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $bufPtr,
            $namePtr,
            $copyLen
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($bufPtr, $copyLen)
        );
        $msgBufSize = 256;
        $msgBuf = $context->builder->alloca($i8->arrayType($msgBufSize), 1, 'undef_global_var_msg');
        $msgBufPtr = $context->builder->pointerCast($msgBuf, $i8p);
        $fmtPtr = $context->builder->pointerCast(
            $context->constantFromString('Undefined global variable $%s'),
            $i8p
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $msgBufPtr,
            $sizeT->constInt($msgBufSize, false),
            $fmtPtr,
            $bufPtr
        );
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $lineVal = $i32->constInt(max(0, $context->callSiteLine), false);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgBufPtr,
            $context->builder->zext($written, $sizeT),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $lineVal
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }
}
