<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Init-safe environ mirror for user-script AOT superglobal refresh (#15417, #18984).
 *
 * Nested {@see EnvironMirrorNativeJitHelper} (fopen/file_get_contents) segfaults at
 * {@code main_after_init}; this libc environ walk mirrors {@see EnvironMirrorNativeJitHelper}
 * for the refresh gate only — same pattern as {@see StringFileGetContentsLibc} (#15309).
 * php-src: sapi/cli/php_cli.c — copy environ into $_SERVER on CLI startup
 */
final class EnvironMirrorRuntimeUserScriptCstr
{
    private const ABI = '__superglobals__mirror_process_environ';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        LibcExtern::register($context);
        self::ensureEnvironGlobal($context);
        $fn = self::declareMirror($context);
        self::emitMirrorBody($context, $fn);
        self::restoreInsertBlock($context, $restore);
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareMirror(Context $context): LlvmFunction
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe) {
            return $probe;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');
        $fn = $context->module->addFunction(
            self::ABI,
            $context->context->functionType($voidTy, false, $htPtr)
        );
        $context->registerFunction(self::ABI, $fn);

        return $fn;
    }

    private static function ensureEnvironGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal('environ')) {
            return;
        }
        $i8pp = $context->getTypeFromString('int8*')->pointerType(0);
        $context->module->addGlobal($i8pp, 'environ');
    }

    private static function emitMirrorBody(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $i64 = $context->getTypeFromString('int64');

        $entry = $fn->appendBasicBlock('environ_mirror_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $idxSlot = $context->builder->alloca($i64, 1);
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $nullHtBb = $fn->appendBasicBlock('environ_mirror_null_ht');
        $bodyBb = $fn->appendBasicBlock('environ_mirror_body');
        $context->builder->branchIf($htNull, $nullHtBb, $bodyBb);

        $context->builder->positionAtEnd($nullHtBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $environGlobal = $context->module->getNamedGlobal('environ');
        if (null === $environGlobal) {
            throw new \LogicException('environ global missing for user-script AOT mirror (#18984)');
        }
        $environPtr = $context->builder->load($environGlobal);
        $environNull = $context->builder->icmp(Builder::INT_EQ, $environPtr, $i8pp->constNull());
        $doneBb = $fn->appendBasicBlock('environ_mirror_done');
        $loopHeadBb = $fn->appendBasicBlock('environ_mirror_loop_head');
        $context->builder->branchIf($environNull, $doneBb, $loopHeadBb);

        $context->builder->positionAtEnd($loopHeadBb);
        $idx = $context->builder->load($idxSlot);
        $entryPtr = $context->builder->gep($environPtr, $idx);
        $pairPtr = $context->builder->load($entryPtr);
        $pairNull = $context->builder->icmp(Builder::INT_EQ, $pairPtr, $i8p->constNull());
        $loopBodyBb = $fn->appendBasicBlock('environ_mirror_loop_body');
        $context->builder->branchIf($pairNull, $doneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $eqPtr = $context->builder->call(
            $context->lookupFunction('strchr'),
            $pairPtr,
            $i32->constInt(ord('='), false)
        );
        $eqNull = $context->builder->icmp(Builder::INT_EQ, $eqPtr, $i8p->constNull());
        $skipBb = $fn->appendBasicBlock('environ_mirror_skip_pair');
        $setBb = $fn->appendBasicBlock('environ_mirror_set_pair');
        $incBb = $fn->appendBasicBlock('environ_mirror_inc');
        $context->builder->branchIf($eqNull, $skipBb, $setBb);

        $context->builder->positionAtEnd($setBb);
        $keyLen = $context->builder->ptrDiff($eqPtr, $pairPtr);
        $keyLenI64 = $context->builder->sext($keyLen, $i64);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $keyLenI64,
            $pairPtr
        );
        $valStart = $context->builder->gep($eqPtr, $i8->constInt(1, false));
        $valLen = $context->builder->call($context->lookupFunction('strlen'), $valStart);
        $valLenI64 = $context->builder->zExt($valLen, $i64);
        $valStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $valLenI64,
            $valStart
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($incBb);
        $nextIdx = $context->builder->add($idx, $i64->constInt(1, false));
        $context->builder->store($nextIdx, $idxSlot);
        $context->builder->branch($loopHeadBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function captureInsertBlock(Context $context): ?\PHPLLVM\BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
