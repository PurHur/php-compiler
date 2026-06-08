<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_trigger_error and undefined-array-key warnings (#7597).
 *
 * Replaces superglobals_refresh.c phpc_stderr_print_cli_error + trigger paths.
 * php-src: Zend/zend_execute_API.c, main/php_errors.c
 */
final class StringTriggerErrorJit
{
    private const MSG_BUF = 512;

    private const TRIGGER_BUF = 4096;

    private const E_ERROR = 256;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
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
        IniRuntime::ensureLinked($context);
        ErrorHandlerJitRuntime::ensureLinked($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__phpc_stderr_print_cli_error', self::emitStderrPrintCliError(...));
        self::implementIfMissing($context, '__compiler_undefined_array_key_warning_cstr', self::emitUndefKeyCstr(...));
        self::implementIfMissing($context, '__compiler_undefined_array_key_warning_long', self::emitUndefKeyLong(...));
        self::implementIfMissing($context, '__compiler_trigger_error', self::emitTriggerError(...));

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
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
        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        return match ($name) {
            '__phpc_stderr_print_cli_error' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i32, $i8p, $i8p, $i32)
            ),
            '__compiler_undefined_array_key_warning_cstr' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeT)
            ),
            '__compiler_undefined_array_key_warning_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i64)
            ),
            '__compiler_trigger_error' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeT, $i32, $i8p, $i32)
            ),
            default => throw new \LogicException('Unknown trigger-error helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');

        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }

        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope($context, 'strlen', $context->context->functionType($i64, false, $i8p));
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'memcpy',
            $context->context->functionType($i8p, false, $i8p, $i8p, $sizeT)
        );
        TypeErrorRaise::ensureDeclInScope($context, 'abort', $context->context->functionType($void, false));
    }

    private static function emitStderrPrintCliError(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stderr_err_entry');
        $context->builder->positionAtEnd($entry);

        $level = $fn->getParam(0);
        $message = $fn->getParam(1);
        $file = $fn->getParam(2);
        $line = $fn->getParam(3);

        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $stderr = $context->module->getNamedGlobal('stderr');
        $stderrPtr = $context->builder->pointerCast($stderr, $i8p);

        $prefix = self::selectErrorPrefix($context, $fn, $level);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $fileEmpty = $context->builder->icmp(Builder::INT_EQ, $file, $i8p->constNull());
        $firstByte = $context->builder->load($file);
        $fileZero = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $noFile = $context->builder->or($fileEmpty, $fileZero);

        $noFileBb = $fn->appendBasicBlock('stderr_no_file');
        $hasFileBb = $fn->appendBasicBlock('stderr_has_file');
        $context->builder->branchIf($noFile, $noFileBb, $hasFileBb);

        $context->builder->positionAtEnd($noFileBb);
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $context->builder->pointerCast($context->constantFromString('%s:  %s\n'), $i8p),
            $prefix,
            $message
        );
        $doneBb = $fn->appendBasicBlock('stderr_done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hasFileBb);
        $linePos = $context->builder->icmp(Builder::INT_SGT, $line, $i32->constInt(0, false));
        $withLineBb = $fn->appendBasicBlock('stderr_with_line');
        $noLineBb = $fn->appendBasicBlock('stderr_no_line');
        $context->builder->branchIf($linePos, $withLineBb, $noLineBb);

        $context->builder->positionAtEnd($withLineBb);
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $context->builder->pointerCast(
                $context->constantFromString('%s:  %s in %s on line %d\n'),
                $i8p
            ),
            $prefix,
            $message,
            $file,
            $line
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($noLineBb);
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $context->builder->pointerCast($context->constantFromString('%s:  %s in %s\n'), $i8p),
            $prefix,
            $message,
            $file
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function selectErrorPrefix(Context $context, LlvmFunction $fn, Value $level): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $unknown = $context->builder->pointerCast(
            $context->constantFromString('PHP Unknown error'),
            $i8p
        );
        $warning = $context->builder->pointerCast($context->constantFromString('PHP Warning'), $i8p);
        $notice = $context->builder->pointerCast($context->constantFromString('PHP Notice'), $i8p);
        $deprecated = $context->builder->pointerCast($context->constantFromString('PHP Deprecated'), $i8p);
        $fatal = $context->builder->pointerCast($context->constantFromString('PHP Fatal error'), $i8p);

        $checkFatal = $fn->appendBasicBlock('prefix_fatal');
        $checkWarn = $fn->appendBasicBlock('prefix_warn');
        $checkNotice = $fn->appendBasicBlock('prefix_notice');
        $checkDep = $fn->appendBasicBlock('prefix_dep');
        $prefixDone = $fn->appendBasicBlock('prefix_done');

        $isFatal = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(self::E_ERROR, false));
        $context->builder->branchIf($isFatal, $checkFatal, $checkWarn);

        $context->builder->positionAtEnd($checkFatal);
        $context->builder->branch($prefixDone);

        $context->builder->positionAtEnd($checkWarn);
        $isWarn = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(2, false));
        $isUserWarn = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(512, false));
        $warnMatch = $context->builder->or($isWarn, $isUserWarn);
        $warnBb = $fn->appendBasicBlock('prefix_warn_hit');
        $context->builder->branchIf($warnMatch, $warnBb, $checkNotice);

        $context->builder->positionAtEnd($warnBb);
        $context->builder->branch($prefixDone);

        $context->builder->positionAtEnd($checkNotice);
        $isNotice = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(8, false));
        $isUserNotice = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(1024, false));
        $noticeMatch = $context->builder->or($isNotice, $isUserNotice);
        $noticeBb = $fn->appendBasicBlock('prefix_notice_hit');
        $context->builder->branchIf($noticeMatch, $noticeBb, $checkDep);

        $context->builder->positionAtEnd($noticeBb);
        $context->builder->branch($prefixDone);

        $context->builder->positionAtEnd($checkDep);
        $isDep = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(8192, false));
        $isUserDep = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(16384, false));
        $depMatch = $context->builder->or($isDep, $isUserDep);
        $depBb = $fn->appendBasicBlock('prefix_dep_hit');
        $unknownBb = $fn->appendBasicBlock('prefix_unknown');
        $context->builder->branchIf($depMatch, $depBb, $unknownBb);

        $context->builder->positionAtEnd($depBb);
        $context->builder->branch($prefixDone);
        $context->builder->positionAtEnd($unknownBb);
        $context->builder->branch($prefixDone);

        $context->builder->positionAtEnd($prefixDone);
        $phi = $context->builder->phi($i8p, 'error_prefix');
        $phi->addIncoming($fatal, $checkFatal);
        $phi->addIncoming($warning, $warnBb);
        $phi->addIncoming($notice, $noticeBb);
        $phi->addIncoming($deprecated, $depBb);
        $phi->addIncoming($unknown, $unknownBb);

        return $phi;
    }

    private static function emitUndefKeyCstr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('undef_key_cstr_entry');
        $context->builder->positionAtEnd($entry);

        $key = $fn->getParam(0);
        $len = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $nullKey = $context->builder->icmp(Builder::INT_EQ, $key, $i8p->constNull());
        $retBb = $fn->appendBasicBlock('undef_key_cstr_ret');
        $bodyBb = $fn->appendBasicBlock('undef_key_cstr_body');
        $context->builder->branchIf($nullKey, $retBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $msgBuf = $context->builder->alloca($i8->arrayType(self::MSG_BUF), 1, 'undef_key_msg');
        $msgPtr = $context->builder->pointerCast($msgBuf, $i8p);
        $lenI32 = $context->builder->trunc($len, $i32);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $msgPtr,
            $sizeT->constInt(self::MSG_BUF, false),
            $context->builder->pointerCast(
                $context->constantFromString('Undefined array key "%.*s"'),
                $i8p
            ),
            $lenI32,
            $key
        );
        self::recordAndMaybePrint($context, $fn, $msgPtr, $i32->constInt(2, false), $retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
    }

    private static function emitUndefKeyLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('undef_key_long_entry');
        $context->builder->positionAtEnd($entry);

        $key = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $msgBuf = $context->builder->alloca($i8->arrayType(self::MSG_BUF), 1, 'undef_key_long_msg');
        $msgPtr = $context->builder->pointerCast($msgBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $msgPtr,
            $sizeT->constInt(self::MSG_BUF, false),
            $context->builder->pointerCast(
                $context->constantFromString('Undefined array key %lld'),
                $i8p
            ),
            $key
        );
        $retBb = $fn->appendBasicBlock('undef_key_long_ret');
        self::recordAndMaybePrint($context, $fn, $msgPtr, $i32->constInt(2, false), $retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
    }

    private static function recordAndMaybePrint(
        Context $context,
        LlvmFunction $fn,
        Value $msgPtr,
        Value $level,
        BasicBlock $retBb
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $zeroLine = $i32->constInt(0, false);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);

        $context->builder->call(
            $context->lookupFunction('__phpc_last_error_record'),
            $level,
            $msgPtr,
            $msgLen,
            $emptyFile,
            $zeroLine
        );

        $enabled = $context->builder->call(
            $context->lookupFunction('__compiler_phpc_error_level_enabled'),
            $level
        );
        $enabledBool = $context->builder->icmp(Builder::INT_NE, $enabled, $i32->constInt(0, false));
        $printBb = $fn->appendBasicBlock('undef_key_print');
        $context->builder->branchIf($enabledBool, $printBb, $retBb);

        $context->builder->positionAtEnd($printBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_stderr_print_cli_error'),
            $level,
            $msgPtr,
            $emptyFile,
            $zeroLine
        );
        $context->builder->branch($retBb);
    }

    private static function emitTriggerError(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('trigger_error_entry');
        $context->builder->positionAtEnd($entry);

        $message = $fn->getParam(0);
        $len = $fn->getParam(1);
        $level = $fn->getParam(2);
        $file = $fn->getParam(3);
        $line = $fn->getParam(4);

        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $nullMsg = $context->builder->icmp(Builder::INT_EQ, $message, $i8p->constNull());
        $retBb = $fn->appendBasicBlock('trigger_error_ret');
        $bodyBb = $fn->appendBasicBlock('trigger_error_body');
        $context->builder->branchIf($nullMsg, $retBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $fileNull = $context->builder->icmp(Builder::INT_EQ, $file, $i8p->constNull());
        $filePhiBb = $fn->appendBasicBlock('trigger_file_phi');
        $useEmptyBb = $fn->appendBasicBlock('trigger_file_empty');
        $useFileBb = $fn->appendBasicBlock('trigger_file_keep');
        $context->builder->branchIf($fileNull, $useEmptyBb, $useFileBb);

        $context->builder->positionAtEnd($useEmptyBb);
        $context->builder->branch($filePhiBb);
        $context->builder->positionAtEnd($useFileBb);
        $context->builder->branch($filePhiBb);
        $context->builder->positionAtEnd($filePhiBb);
        $fileVal = $context->builder->phi($i8p, 'trigger_file');
        $fileVal->addIncoming($emptyFile, $useEmptyBb);
        $fileVal->addIncoming($file, $useFileBb);

        $lineNeg = $context->builder->icmp(Builder::INT_SLT, $line, $i32->constInt(0, false));
        $linePhiBb = $fn->appendBasicBlock('trigger_line_phi');
        $lineZeroBb = $fn->appendBasicBlock('trigger_line_zero');
        $lineKeepBb = $fn->appendBasicBlock('trigger_line_keep');
        $context->builder->branchIf($lineNeg, $lineZeroBb, $lineKeepBb);
        $context->builder->positionAtEnd($lineZeroBb);
        $context->builder->branch($linePhiBb);
        $context->builder->positionAtEnd($lineKeepBb);
        $context->builder->branch($linePhiBb);
        $context->builder->positionAtEnd($linePhiBb);
        $lineVal = $context->builder->phi($i32, 'trigger_line');
        $lineVal->addIncoming($i32->constInt(0, false), $lineZeroBb);
        $lineVal->addIncoming($line, $lineKeepBb);

        $context->builder->call(
            $context->lookupFunction('__phpc_last_error_record'),
            $level,
            $message,
            $len,
            $fileVal,
            $lineVal
        );

        $enabled = $context->builder->call(
            $context->lookupFunction('__compiler_phpc_error_level_enabled'),
            $level
        );
        $enabledBool = $context->builder->icmp(Builder::INT_NE, $enabled, $i32->constInt(0, false));
        $afterEnabledBb = $fn->appendBasicBlock('trigger_after_enabled');
        $context->builder->branchIf($enabledBool, $afterEnabledBb, $retBb);

        $context->builder->positionAtEnd($afterEnabledBb);
        $dispatched = $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_dispatch'),
            $level,
            $message,
            $len,
            $lineVal
        );
        $handled = $context->builder->icmp(Builder::INT_NE, $dispatched, $i32->constInt(0, false));
        $handledBb = $fn->appendBasicBlock('trigger_handled');
        $stderrBb = $fn->appendBasicBlock('trigger_stderr');
        $context->builder->branchIf($handled, $handledBb, $stderrBb);

        $context->builder->positionAtEnd($handledBb);
        $isFatal = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(self::E_ERROR, false));
        $abortBb = $fn->appendBasicBlock('trigger_abort_handled');
        $context->builder->branchIf($isFatal, $abortBb, $retBb);
        $context->builder->positionAtEnd($abortBb);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($stderrBb);
        $buf = $context->builder->alloca($i8->arrayType(self::TRIGGER_BUF), 1, 'trigger_msg');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $maxCopy = $sizeT->constInt(self::TRIGGER_BUF - 1, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGE, $len, $maxCopy),
            $maxCopy,
            $len
        );
        $context->builder->call($context->lookupFunction('memcpy'), $bufPtr, $message, $copyLen);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($bufPtr, $copyLen)
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_stderr_print_cli_error'),
            $level,
            $bufPtr,
            $fileVal,
            $lineVal
        );
        $fatalAfterBb = $fn->appendBasicBlock('trigger_fatal_after');
        $context->builder->branch($fatalAfterBb);
        $context->builder->positionAtEnd($fatalAfterBb);
        $isFatalAfter = $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(self::E_ERROR, false));
        $abortAfterBb = $fn->appendBasicBlock('trigger_abort_after');
        $context->builder->branchIf($isFatalAfter, $abortAfterBb, $retBb);
        $context->builder->positionAtEnd($abortAfterBb);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
    }
}
