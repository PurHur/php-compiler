<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ob_end_clean;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Lazy minimal ob stack for user-script AOT exec stdout capture (#10492, #19136, #20169, #21476).
 *
 * Always {@see \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper} via {@see JitVmHelperLink}
 * (embed + thin standalone / user-script AOT). Former thin-standalone LLVM kernel fork deleted —
 * helper direct stdout uses {@see phpc_ob_write_stdout_kernel} (#21469 shape).
 * php-src: ext/standard/output.c
 */
final class ObOutputExecCaptureRuntime
{
    private const HELPER_PATH = '/ext/standard/ObOutputExecCaptureJitHelper.php';

    private const START = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::start';

    private const APPEND = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::appendString';

    private const GET_CLEAN = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::getClean';

    private const LEVEL = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::getLevel';

    private const HAS_BUFFER = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::hasActiveBuffer';

    private const CONTENTS = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::getContents';

    private const LENGTH = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::getLength';

    private const END_CLEAN = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::endClean';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::START,
        self::APPEND,
        self::GET_CLEAN,
        self::LEVEL,
        self::HAS_BUFFER,
        self::CONTENTS,
        self::LENGTH,
        self::END_CLEAN,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (self::isFullyLinked($context)) {
            return;
        }

        $restore = self::captureInsertBlock($context);
        ObOutputJitBridge::prepareUserScriptEmit($context);
        ObOutputEchoJitEmit::ensureEchoAbiDeclared($context);
        self::ensureAppendBytesDeclared($context);
        StringTriggerErrorJit::implement($context);
        self::ensureHelperCompiled($context);
        self::implementStart($context);
        self::implementAppendBytes($context);
        self::implementGetClean($context);
        self::implementReadApi($context);
        self::restoreInsertBlock($context, $restore);
        if (null === $restore) {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Lazy link when echo/start already emitted but ob_get_* / ob_end_clean lowered later (#4914). */
    public static function ensureReadApiLinked(Context $context): void
    {
        if (self::isReadApiLinked($context)) {
            return;
        }

        $restore = self::captureInsertBlock($context);
        ObOutputJitBridge::prepareUserScriptEmit($context);
        StringTriggerErrorJit::implement($context);
        self::ensureHelperCompiled($context);
        self::implementReadApi($context);
        self::restoreInsertBlock($context, $restore);
        if (null === $restore) {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function isFullyLinked(Context $context): bool
    {
        $append = $context->module->getNamedFunction('__phpc_ob_append_bytes');

        return null !== $append
            && $append->countBasicBlocks() > 0
            && self::isReadApiLinked($context);
    }

    private static function isReadApiLinked(Context $context): bool
    {
        $contents = $context->module->getNamedFunction('__phpc_ob_get_contents');

        return null !== $contents && $contents->countBasicBlocks() > 0;
    }

    private static function implementReadApi(Context $context): void
    {
        self::implementGetLevel($context);
        self::implementGetContents($context);
        self::implementGetLength($context);
        self::implementEndClean($context);
    }

    private static function ensureHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19136'
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureHelperCompiled($context);
        $fn = $context->functions[\strtolower($logical)] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ObOutputExecCaptureJitHelper compile (#19136)');
        }

        return $fn;
    }

    private static function implementStart(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_start', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_start_entry');
            $context->builder->positionAtEnd($entry);
            $context->builder->call(self::helperFunction($context, self::START));
            $context->builder->returnVoid();
        });
    }

    private static function implementAppendBytes(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_append_bytes', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_append_entry');
            $done = $fn->appendBasicBlock('oec_append_done');
            $skip = $fn->appendBasicBlock('oec_append_skip');
            $work = $fn->appendBasicBlock('oec_append_work');
            $context->builder->positionAtEnd($entry);
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i64 = $context->getTypeFromString('int64');
            $data = $fn->getParam(0);
            $len = $fn->getParam(1);
            $zero = $sizeT->constInt(0, false);
            $bad = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $data, $i8p->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $len, $zero)
            );
            $context->builder->branchIf($bad, $skip, $work);
            $context->builder->positionAtEnd($work);
            $chunk = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->sext($len, $i64),
                $data
            );
            $context->builder->call(
                self::helperFunction($context, self::APPEND),
                $context->builder->call($context->lookupFunction('__string__separate'), $chunk)
            );
            $context->builder->branch($done);
            $context->builder->positionAtEnd($skip);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $context->builder->returnVoid();
        });
    }

    private static function implementGetClean(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_clean', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_get_clean_entry');
            $fail = $fn->appendBasicBlock('oec_get_clean_fail');
            $okBb = $fn->appendBasicBlock('oec_get_clean_ok');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::GET_CLEAN),
                []
            );
            $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
            $context->builder->branchIf($isNull, $fail, $okBb);
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
            $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementGetLevel(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_level', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_get_level_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $raw = $context->builder->call(self::helperFunction($context, self::LEVEL));
            $context->builder->returnValue($context->builder->trunc($raw, $i32));
        });
    }

    private static function implementGetContents(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_contents', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_get_contents_entry');
            $fail = $fn->appendBasicBlock('oec_get_contents_fail');
            $work = $fn->appendBasicBlock('oec_get_contents_work');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $has = $context->builder->call(self::helperFunction($context, self::HAS_BUFFER));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->trunc($has, $i32), $i32->constInt(0, false)),
                $fail,
                $work
            );
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::CONTENTS),
                []
            );
            $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
            $nullBb = $fn->appendBasicBlock('oec_get_contents_null');
            $okBb = $fn->appendBasicBlock('oec_get_contents_ok');
            $context->builder->branchIf($isNull, $nullBb, $okBb);
            $context->builder->positionAtEnd($nullBb);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
            $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementGetLength(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_length', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_get_length_entry');
            $fail = $fn->appendBasicBlock('oec_get_length_fail');
            $work = $fn->appendBasicBlock('oec_get_length_work');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $has = $context->builder->call(self::helperFunction($context, self::HAS_BUFFER));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->trunc($has, $i32), $i32->constInt(0, false)),
                $fail,
                $work
            );
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $len = $context->builder->call(self::helperFunction($context, self::LENGTH));
            $neg = $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(0, false));
            $negBb = $fn->appendBasicBlock('oec_get_length_neg');
            $okBb = $fn->appendBasicBlock('oec_get_length_ok');
            $context->builder->branchIf($neg, $negBb, $okBb);
            $context->builder->positionAtEnd($negBb);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $len);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementEndClean(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_end_clean', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_end_clean_entry');
            $fail = $fn->appendBasicBlock('oec_end_clean_fail');
            $work = $fn->appendBasicBlock('oec_end_clean_work');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $has = $context->builder->call(self::helperFunction($context, self::HAS_BUFFER));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->trunc($has, $i32), $i32->constInt(0, false)),
                $fail,
                $work
            );
            $context->builder->positionAtEnd($fail);
            self::emitObNoBufferNotice($context, ob_end_clean::NO_BUFFER_NOTICE);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $ok = $context->builder->call(self::helperFunction($context, self::END_CLEAN));
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $out,
                $context->builder->trunc($ok, $i32)
            );
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function emitObNoBufferNotice(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->sext($msgLen, $sizeT),
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function ensureAppendBytesDeclared(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ob_append_bytes');
        if (null !== $probe) {
            $context->registerFunction('__phpc_ob_append_bytes', $probe);

            return;
        }
        $void = $context->context->voidType();
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = $context->module->addFunction(
            '__phpc_ob_append_bytes',
            $context->context->functionType($void, false, $i8p, $sizeT)
        );
        $context->registerFunction('__phpc_ob_append_bytes', $fn);
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
        $fn = $probe;
        if (null === $fn) {
            throw new \LogicException($name.' not declared before ObOutputExecCaptureRuntime (#19136)');
        }
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
