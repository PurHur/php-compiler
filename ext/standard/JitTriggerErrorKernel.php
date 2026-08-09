<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ErrorHandlerJitRuntime;
use PHPCompiler\JIT\Builtin\LastErrorRuntime;
use PHPCompiler\JIT\Builtin\SilenceRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT NestedJIT bridges for __compiler_trigger_error / undefined-array-key (#9293, #19864, #21300).
 *
 * Quarantined from lib/JIT/Builtin/StringTriggerErrorJit — {@see \PHPCompiler\JIT\Builtin\StringTriggerErrorJit}
 * stays the thin orchestrator. User-handler dispatch stays in {@see ErrorHandlerJitRuntime}.
 *
 * Standalone AOT (#21300): drop dishonest no-op thin ABI. trigger_error records via
 * {@see LastErrorRuntime} and prints via thin libc fprintf (user-script AOT has no
 * honest PHP fwrite(STDERR)). Undefined-array-key NestedJITs {@see TriggerErrorJitHelper}.
 *
 * php-src: Zend/zend_execute_API.c, main/php_errors.c
 */
final class JitTriggerErrorKernel
{
    private const UNDEF_HELPER_PATH = '/ext/standard/TriggerErrorJitHelper.php';

    private const UNDEF_KEY_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::undefinedArrayKey';

    private const UNDEF_KEY_LONG_HELPER = 'PHPCompiler\\ext\\standard\\TriggerErrorJitHelper::undefinedArrayKeyLong';

    private const E_USER_ERROR = 256;

    /** @var list<string> */
    private const UNDEF_HELPERS = [
        self::UNDEF_KEY_HELPER,
        self::UNDEF_KEY_LONG_HELPER,
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

        self::ensureUndefHelpersCompiled($context);
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

        // User-script AOT has no honest PHP fwrite(STDERR) link (#21300) — thin libc fprintf
        // matches ErrorRaise / TypeErrorRaise. php-src: main/php_errors.c php_error_cb
        self::ensureStderrLibcDecls($context);

        $i32 = $context->getTypeFromString('int32');
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

        $nullMsg = $context->builder->icmp(Builder::INT_EQ, $message, $i8p->constNull());
        $retBb = $fn->appendBasicBlock('stderr_err_ret');
        $bodyBb = $fn->appendBasicBlock('stderr_err_body');
        $context->builder->branchIf($nullMsg, $retBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $prefix = self::emitCliStderrPrefix($context, $fn, $level);
        $fileCstr = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $file, $i8p->constNull()),
            $context->builder->pointerCast($context->constantFromString('Unknown'), $i8p),
            $file
        );
        $stderrPtr = self::stderrFilePtr($context);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString("%s:  %s in %s on line %d\n"),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $fmt,
            $prefix,
            $message,
            $fileCstr,
            $line
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    /** Zend CLI stderr prefixes (ErrorReporter::cliStderrPrefix). */
    private static function emitCliStderrPrefix(Context $context, LlvmFunction $fn, Value $level): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $doneBb = $fn->appendBasicBlock('stderr_prefix_done');

        $cases = [
            [512, 'PHP Warning'],   // E_USER_WARNING
            [2, 'PHP Warning'],     // E_WARNING
            [1024, 'PHP Notice'],   // E_USER_NOTICE
            [8, 'PHP Notice'],      // E_NOTICE
            [16384, 'PHP Deprecated'], // E_USER_DEPRECATED
            [8192, 'PHP Deprecated'],  // E_DEPRECATED
            [self::E_USER_ERROR, 'PHP Fatal error'],
        ];

        $incoming = [];
        $curBb = $context->builder->getInsertBlock();
        foreach ($cases as [$code, $label]) {
            $matchBb = $fn->appendBasicBlock('stderr_prefix_'.$code);
            $nextBb = $fn->appendBasicBlock('stderr_prefix_next_'.$code);
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $level,
                $i32->constInt($code, false)
            );
            $context->builder->branchIf($isMatch, $matchBb, $nextBb);
            $context->builder->positionAtEnd($matchBb);
            $cstr = $context->builder->pointerCast($context->constantFromString($label), $i8p);
            $end = $context->builder->getInsertBlock();
            $context->builder->branch($doneBb);
            $incoming[] = [$cstr, $end];
            $context->builder->positionAtEnd($nextBb);
            $curBb = $nextBb;
        }
        $fallback = $context->builder->pointerCast(
            $context->constantFromString('PHP Unknown error'),
            $i8p
        );
        $fallbackEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);
        $incoming[] = [$fallback, $fallbackEnd];

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i8p, 'stderr_prefix');
        foreach ($incoming as [$val, $bb]) {
            $phi->addIncoming($val, $bb);
        }

        return $phi;
    }

    private static function ensureStderrLibcDecls(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
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

        // Handler return true swallows E_USER_ERROR and continues (php-src; #29216).
        $context->builder->positionAtEnd($handledBb);
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($afterHandlerBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_last_error_record'),
            $level,
            $message,
            $len,
            $file,
            $line
        );
        // Gate print on error_reporting mask via getErrorReporting (NestedJIT
        // isErrorLevelEnabled bool lowering is unreliable on standalone AOT — #21300).
        $erLogical = 'PHPCompiler\\ext\\standard\\ErrorSilenceJitHelper::getErrorReporting';
        $erFn = $context->functions[\strtolower($erLogical)] ?? null;
        $stderrBb = $fn->appendBasicBlock('trigger_error_stderr');
        if (null !== $erFn) {
            $er = $context->builder->call($erFn);
            $masked = $context->builder->and(
                $er,
                $context->builder->zExt($level, $context->getTypeFromString('int64'))
            );
            $shouldPrint = $context->builder->icmp(
                Builder::INT_NE,
                $masked,
                $context->getTypeFromString('int64')->constInt(0, false)
            );
            $context->builder->branchIf($shouldPrint, $stderrBb, $retBb);
        } else {
            $context->builder->branch($stderrBb);
        }

        $context->builder->positionAtEnd($stderrBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_stderr_print_cli_error'),
            $level,
            $message,
            $file,
            $line
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
        self::ensureUndefHelpersCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after TriggerError helper compile (#21300)');
        }

        return $fn;
    }

    private static function ensureUndefHelpersCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::UNDEF_HELPER_PATH,
            self::UNDEF_HELPERS,
            '#21300'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitTriggerErrorKernel bridge (#21300)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
