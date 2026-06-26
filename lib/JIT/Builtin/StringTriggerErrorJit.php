<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_trigger_error and undefined-array-key warnings via TriggerErrorJitHelper PHP (#9293).
 *
 * Replaces ~530-line LLVM stderr/trigger paths. User-handler dispatch stays in {@see ErrorHandlerJitRuntime}.
 * php-src: Zend/zend_execute_API.c, main/php_errors.c
 */
final class StringTriggerErrorJit
{
    private const HELPER_PATH = '/ext/standard/TriggerErrorJitHelper.php';

    private const STDERR_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::stderrPrintCliError';

    private const UNDEF_KEY_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::undefinedArrayKey';

    private const UNDEF_KEY_LONG_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::undefinedArrayKeyLong';

    private const RECORD_TRIGGER_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::recordTrigger';

    private const SHOULD_PRINT_TRIGGER_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::shouldPrintTrigger';

    private const RECORD_TRIGGER_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::recordTriggerError';

    private const E_USER_ERROR = 256;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STDERR_HELPER,
        self::UNDEF_KEY_HELPER,
        self::UNDEF_KEY_LONG_HELPER,
        self::RECORD_TRIGGER_HELPER,
        self::SHOULD_PRINT_TRIGGER_HELPER,
        self::RECORD_TRIGGER_ERROR_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_stderr_print_cli_error',
        '__compiler_undefined_array_key_warning_cstr',
        '__compiler_undefined_array_key_warning_long',
        '__compiler_trigger_error',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_trigger_error');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        LastErrorRuntime::ensureLinked($context);
        SilenceRuntime::ensureLinked($context);
        ErrorHandlerJitRuntime::ensureLinked($context);

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementStandaloneThinAbi($context);
            self::registerLinkedRuntime($context);
            $context->builder->clearInsertionPosition();

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::ensureValueHelpers($context);
        self::implementStderrPrintBridge($context);
        self::implementUndefKeyCstrBridge($context);
        self::implementUndefKeyLongBridge($context);
        self::implementTriggerErrorBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /** Load libc stderr FILE* (external global), matching StreamGlobalsJit. */
    public static function stderrFilePtr(Context $context): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }
        $stderrGlobal = $context->module->getNamedGlobal('stderr');

        return $context->builder->load(
            $context->builder->pointerCast($stderrGlobal, $i8p->pointerType(0))
        );
    }

    private static function implementStderrPrintBridge(Context $context): void
    {
        $abiName = '__phpc_stderr_print_cli_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i32, $i8p, $i8p, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('stderr_err_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $level = $fn->getParam(0);
        $message = $fn->getParam(1);
        $file = $fn->getParam(2);
        $line = $fn->getParam(3);
        $msgStr = self::nullTerminatedCstrToString($context, $fn, $message);
        $fileStr = self::nullSafeCstrToString($context, $fn, $file);
        $context->builder->call(
            self::helperFunction($context, self::STDERR_HELPER),
            $context->builder->sext($level, $i64),
            $msgStr,
            $fileStr,
            $context->builder->sext($line, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementUndefKeyCstrBridge(Context $context): void
    {
        $abiName = '__compiler_undefined_array_key_warning_cstr';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('undef_key_cstr_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $key = $fn->getParam(0);
        $len = $fn->getParam(1);
        $nullKey = $context->builder->icmp(Builder::INT_EQ, $key, $i8p->constNull());
        $retBb = $fn->appendBasicBlock('undef_key_cstr_ret');
        $bodyBb = $fn->appendBasicBlock('undef_key_cstr_body');
        $context->builder->branchIf($nullKey, $retBb, $bodyBb);
        $context->builder->positionAtEnd($bodyBb);
        $i64 = $context->getTypeFromString('int64');
        $keyStr = self::cstrToStringWithLength($context, $key, $context->builder->zExt($len, $i64));
        $context->builder->call(self::helperFunction($context, self::UNDEF_KEY_HELPER), $keyStr);
        $context->builder->branch($retBb);
        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementUndefKeyLongBridge(Context $context): void
    {
        $abiName = '__compiler_undefined_array_key_warning_long';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('undef_key_long_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::UNDEF_KEY_LONG_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTriggerErrorBridge(Context $context): void
    {
        $abiName = '__compiler_trigger_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT, $i32, $i8p, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('trigger_error_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $message = $fn->getParam(0);
        $len = $fn->getParam(1);
        $level = $fn->getParam(2);
        $file = $fn->getParam(3);
        $line = $fn->getParam(4);
        $retBb = $fn->appendBasicBlock('trigger_error_ret');

        $nullMsg = $context->builder->icmp(Builder::INT_EQ, $message, $i8p->constNull());
        $bodyBb = $fn->appendBasicBlock('trigger_error_body');
        $context->builder->branchIf($nullMsg, $retBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $msgStr = self::cstrToStringWithLength($context, $message, $context->builder->zExt($len, $i64));
        $fileStr = self::nullSafeCstrToString($context, $fn, $file);
        $dispatched = $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_dispatch'),
            $level,
            $message,
            $len,
            $line
        );
        $zeroI32 = $i32->constInt(0, false);
        $handled = $context->builder->icmp(Builder::INT_NE, $dispatched, $zeroI32);
        $handledBb = $fn->appendBasicBlock('trigger_error_handled');
        $afterHandlerBb = $fn->appendBasicBlock('trigger_error_after_handler');
        $context->builder->branchIf($handled, $handledBb, $afterHandlerBb);

        $context->builder->positionAtEnd($handledBb);
        $isFatal = $context->builder->icmp(
            Builder::INT_EQ,
            $level,
            $i32->constInt(self::E_USER_ERROR, false)
        );
        $abortBb = $fn->appendBasicBlock('trigger_error_abort_handled');
        $context->builder->branchIf($isFatal, $abortBb, $retBb);
        $context->builder->positionAtEnd($abortBb);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($afterHandlerBb);
        $context->builder->call(
            self::helperFunction($context, self::RECORD_TRIGGER_ERROR_HELPER),
            $context->builder->sext($level, $i64),
            $msgStr,
            $fileStr,
            $context->builder->sext($line, $i64)
        );
        $shouldContinue = $context->builder->call(
            self::helperFunction($context, self::SHOULD_PRINT_TRIGGER_HELPER),
            $context->builder->sext($level, $i64)
        );
        $stderrBb = $fn->appendBasicBlock('trigger_error_stderr');
        $context->builder->branchIf($shouldContinue, $stderrBb, $retBb);

        $context->builder->positionAtEnd($stderrBb);
        $context->builder->call(
            self::helperFunction($context, self::STDERR_HELPER),
            $context->builder->sext($level, $i64),
            $msgStr,
            $fileStr,
            $context->builder->sext($line, $i64)
        );
        $fatalAfterBb = $fn->appendBasicBlock('trigger_error_fatal_after');
        $context->builder->branch($fatalAfterBb);
        $context->builder->positionAtEnd($fatalAfterBb);
        $isFatalAfter = $context->builder->icmp(
            Builder::INT_EQ,
            $level,
            $i32->constInt(self::E_USER_ERROR, false)
        );
        $abortAfterBb = $fn->appendBasicBlock('trigger_error_abort_after');
        $context->builder->branchIf($isFatalAfter, $abortAfterBb, $retBb);
        $context->builder->positionAtEnd($abortAfterBb);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    /** Standalone AOT: thin LLVM ABI without compiled TriggerErrorJitHelper PHP (#9293). */
    private static function implementStandaloneThinAbi(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $savedBuilder = $context->builder;

        foreach (
            [
                '__phpc_stderr_print_cli_error' => $context->context->functionType($voidTy, false, $i32, $i8p, $i8p, $i32),
                '__compiler_undefined_array_key_warning_cstr' => $context->context->functionType($voidTy, false, $i8p, $sizeT),
                '__compiler_undefined_array_key_warning_long' => $context->context->functionType($voidTy, false, $i64),
                '__compiler_trigger_error' => $context->context->functionType($voidTy, false, $i8p, $sizeT, $i32, $i8p, $i32),
            ] as $abiName => $ft
        ) {
            $fn = self::standaloneAbiFunction($context, $abiName, $ft);
            if ($fn->countBasicBlocks() > 0) {
                $context->registerFunction($abiName, $fn);
                continue;
            }
            $entry = $fn->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
            $context->registerFunction($abiName, $fn);
        }

        $context->builder = $savedBuilder;
    }

    private static function standaloneAbiFunction(Context $context, string $abiName, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null === $probe) {
            $context->module->addFunction($abiName, $ft);
            $probe = $context->module->getNamedFunction($abiName);
        }
        if (null === $probe) {
            throw new \LogicException($abiName.' missing after standalone ABI declare (#9293)');
        }

        return $probe;
    }

    private static function nullSafeCstrToString(Context $context, LlvmFunction $fn, Value $ptr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $emptyBb = $fn->appendBasicBlock('cstr_empty');
        $useBb = $fn->appendBasicBlock('cstr_use');
        $doneBb = $fn->appendBasicBlock('cstr_done');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $i8p->constNull());
        $context->builder->branchIf($isNull, $emptyBb, $useBb);
        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($useBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming(self::literalEmptyString($context), $emptyBb);
        $phi->addIncoming(self::nullTerminatedCstrToString($context, $fn, $ptr), $useBb);

        return $phi;
    }

    private static function nullTerminatedCstrToString(Context $context, LlvmFunction $fn, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return self::cstrToStringWithLength($context, $cstr, $context->builder->zExt($len, $i64));
    }

    private static function literalEmptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $charPtr)
        );
    }

    private static function cstrToStringWithLength(Context $context, Value $cstr, Value $lenI64): Value
    {
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function ensureValueHelpers(Context $context): void
    {
        TypeErrorRaise::ensureDeclInScope($context, 'strlen', $context->context->functionType(
            $context->getTypeFromString('int64'),
            false,
            $context->getTypeFromString('int8*')
        ));
        TypeErrorRaise::ensureDeclInScope($context, 'abort', $context->context->functionType(
            $context->getTypeFromString('void'),
            false
        ));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after TriggerErrorJitHelper compile (#9293)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'TriggerErrorJitHelper.php');
            if (null === $block) {
                throw new \LogicException('TriggerErrorJitHelper.php parseAndCompile failed (#9293)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9293)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringTriggerErrorJit bridge (#9293)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
