<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObOutputEchoJitEmit;
use PHPCompiler\JIT\Builtin\ObOutputJitBridge;
use PHPCompiler\JIT\Builtin\ObStorageGlobals;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin standalone AOT ob stack via ObStorageGlobals LLVM (#10492, #15407, #19576, #20169).
 *
 * Moved out of lib/JIT/Builtin/ — {@see \PHPCompiler\JIT\Builtin\ObOutputExecCaptureRuntime}
 * stays the thin orchestrator. Nested-JIT {@see ObOutputExecCaptureJitHelper} segfaults under
 * {@see \PHPCompiler\JIT\Context::isThinStandaloneAotMain()} (#16075); this path keeps exec
 * stdout capture + ob read/discard API on fixed char buffers (#4914).
 * php-src: ext/standard/output.c
 */
final class JitObOutputExecCaptureKernel
{
    public static function ensureLinked(Context $context): void
    {
        if (self::isFullyLinked($context)) {
            return;
        }

        $restore = self::captureInsertBlock($context);
        ObOutputJitBridge::prepareUserScriptEmit($context);
        ObOutputEchoJitEmit::ensureEchoAbiDeclared($context);
        self::ensureAppendBytesDeclared($context);
        self::ensureWriteLibc($context);
        ObStorageGlobals::ensureGlobals($context);
        self::implementStart($context);
        self::implementAppendBytes($context);
        self::implementGetClean($context);
        StringTriggerErrorJit::implement($context);
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
        self::ensureWriteLibc($context);
        StringTriggerErrorJit::implement($context);
        ObStorageGlobals::ensureGlobals($context);
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

    private static function implementStart(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_start', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_start_entry');
            $skip = $fn->appendBasicBlock('oec_llvm_start_skip');
            $work = $fn->appendBasicBlock('oec_llvm_start_work');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $levelPtr = self::levelPtr($context);
            $level = $context->builder->load($levelPtr);
            $atMax = $context->builder->icmp(
                Builder::INT_SGE,
                $level,
                $i32->constInt(ObStackLimits::MAX_DEPTH, false)
            );
            $context->builder->branchIf($atMax, $skip, $work);
            $context->builder->positionAtEnd($work);
            $context->builder->store($i64->constInt(0, false), self::lenElemPtr($context, $level));
            $context->builder->store(
                $context->getTypeFromString('int8')->constInt(0, false),
                self::storageRowPtr($context, $level)
            );
            $context->builder->store($context->builder->add($level, $i32->constInt(1, false)), $levelPtr);
            $context->builder->branch($skip);
            $context->builder->positionAtEnd($skip);
            $context->builder->returnVoid();
        });
    }

    private static function implementAppendBytes(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_append_bytes', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_append_entry');
            $done = $fn->appendBasicBlock('oec_llvm_append_done');
            $skip = $fn->appendBasicBlock('oec_llvm_append_skip');
            $direct = $fn->appendBasicBlock('oec_llvm_append_direct');
            $check = $fn->appendBasicBlock('oec_llvm_append_check');
            $memcpyBb = $fn->appendBasicBlock('oec_llvm_append_memcpy');
            $work = $fn->appendBasicBlock('oec_llvm_append_work');
            $context->builder->positionAtEnd($entry);
            $i8p = $context->getTypeFromString('int8*');
            $i8 = $context->getTypeFromString('int8');
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $sizeT = $context->getTypeFromString('size_t');
            $data = $fn->getParam(0);
            $len = $fn->getParam(1);
            $zero = $sizeT->constInt(0, false);
            $bad = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $data, $i8p->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $len, $zero)
            );
            $context->builder->branchIf($bad, $skip, $check);
            $context->builder->positionAtEnd($skip);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($check);
            $levelPtr = self::levelPtr($context);
            $level = $context->builder->load($levelPtr);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(0, false)),
                $direct,
                $memcpyBb
            );
            $context->builder->positionAtEnd($direct);
            $context->builder->call(
                $context->lookupFunction('write'),
                $i32->constInt(1, false),
                $data,
                $context->builder->zExt($len, $i64)
            );
            $context->builder->branch($done);
            $context->builder->positionAtEnd($memcpyBb);
            $idx = $context->builder->sub($level, $i32->constInt(1, false));
            $usedPtr = self::lenElemPtr($context, $idx);
            $used = $context->builder->load($usedPtr);
            $cap = $i64->constInt(ObStackLimits::BUF_SIZE - 1, false);
            $full = $context->builder->icmp(Builder::INT_SGE, $used, $cap);
            $context->builder->branchIf($full, $done, $work);
            $context->builder->positionAtEnd($work);
            $room = $context->builder->sub($cap, $used);
            $copyLen = $context->builder->select(
                $context->builder->icmp(Builder::INT_ULT, $context->builder->zExt($len, $i64), $room),
                $context->builder->zExt($len, $i64),
                $room
            );
            $dest = $context->builder->inBoundsGEP(
                self::storageRowPtr($context, $idx),
                $context->builder->trunc($used, $i64)
            );
            $context->builder->call(
                $context->lookupFunction('memcpy'),
                $dest,
                $data,
                $copyLen
            );
            $newUsed = $context->builder->add($used, $copyLen);
            $context->builder->store($newUsed, $usedPtr);
            $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($dest, $copyLen));
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $context->builder->returnVoid();
        });
    }

    private static function implementGetClean(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_clean', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_get_clean_entry');
            $fail = $fn->appendBasicBlock('oec_llvm_get_clean_fail');
            $okBb = $fn->appendBasicBlock('oec_llvm_get_clean_ok');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $levelPtr = self::levelPtr($context);
            $level = $context->builder->load($levelPtr);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(0, false)),
                $fail,
                $okBb
            );
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $idx = $context->builder->sub($level, $i32->constInt(1, false));
            $len = $context->builder->load(self::lenElemPtr($context, $idx));
            $row = self::storageRowPtr($context, $idx);
            $str = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $len,
                $row
            );
            $context->builder->store($context->builder->sub($level, $i32->constInt(1, false)), $levelPtr);
            $context->builder->store($i64->constInt(0, false), self::lenElemPtr($context, $idx));
            $context->builder->store(
                $context->getTypeFromString('int8')->constInt(0, false),
                $row
            );
            $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementGetLevel(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_level', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_get_level_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $level = $context->builder->load(self::levelPtr($context));
            $context->builder->returnValue($level);
        });
    }

    private static function implementGetContents(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_contents', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_get_contents_entry');
            $fail = $fn->appendBasicBlock('oec_llvm_get_contents_fail');
            $okBb = $fn->appendBasicBlock('oec_llvm_get_contents_ok');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $level = $context->builder->load(self::levelPtr($context));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(0, false)),
                $fail,
                $okBb
            );
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $idx = $context->builder->sub($level, $i32->constInt(1, false));
            $len = $context->builder->load(self::lenElemPtr($context, $idx));
            $str = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $len,
                self::storageRowPtr($context, $idx)
            );
            $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementGetLength(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_length', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_get_length_entry');
            $fail = $fn->appendBasicBlock('oec_llvm_get_length_fail');
            $okBb = $fn->appendBasicBlock('oec_llvm_get_length_ok');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $level = $context->builder->load(self::levelPtr($context));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(0, false)),
                $fail,
                $okBb
            );
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $idx = $context->builder->sub($level, $i32->constInt(1, false));
            $len = $context->builder->load(self::lenElemPtr($context, $idx));
            $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $len);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementEndClean(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_end_clean', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_llvm_end_clean_entry');
            $fail = $fn->appendBasicBlock('oec_llvm_end_clean_fail');
            $okBb = $fn->appendBasicBlock('oec_llvm_end_clean_ok');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $i8 = $context->getTypeFromString('int8');
            $levelPtr = self::levelPtr($context);
            $level = $context->builder->load($levelPtr);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $level, $i32->constInt(0, false)),
                $fail,
                $okBb
            );
            $context->builder->positionAtEnd($fail);
            self::emitObNoBufferNotice($context, ob_end_clean::NO_BUFFER_NOTICE);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $idx = $context->builder->sub($level, $i32->constInt(1, false));
            $row = self::storageRowPtr($context, $idx);
            $context->builder->store($context->builder->sub($level, $i32->constInt(1, false)), $levelPtr);
            $context->builder->store($i64->constInt(0, false), self::lenElemPtr($context, $idx));
            $context->builder->store($i8->constInt(0, false), $row);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(1, false));
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function emitObNoBufferNotice(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
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

    private static function levelPtr(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEVEL);

        return $context->builder->pointerCast($global, $i32->pointerType(0));
    }

    private static function lenElemPtr(Context $context, Value $idx): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $global = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEN);
        $ptr = $context->builder->pointerCast($global, $i64->pointerType(0));

        return $context->builder->inBoundsGEP($ptr, $context->builder->sext($idx, $i64));
    }

    private static function storageRowPtr(Context $context, Value $idx): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $storage = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_STORAGE);
        $rowTy = $i8->arrayType(ObStackLimits::BUF_SIZE);
        $storageTy = $rowTy->arrayType(ObStackLimits::MAX_DEPTH);
        $base = $context->builder->pointerCast($storage, $storageTy->pointerType(0));
        $row = $context->builder->inBoundsGEP($base, $i64->constInt(0, false), $context->builder->sext($idx, $i64));

        return $context->builder->pointerCast($row, $i8->pointerType(0));
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

    private static function ensureWriteLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        foreach (
            [
                'write' => [$i64, false, [$i32, $i8p, $sizeT]],
                'memcpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
                'strlen' => [$sizeT, false, [$i8p]],
            ] as $name => $spec
        ) {
            [$ret, $vararg, $params] = $spec;
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
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
        $fn = $probe;
        if (null === $fn) {
            throw new \LogicException($name.' not declared before JitObOutputExecCaptureKernel (#10492)');
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
