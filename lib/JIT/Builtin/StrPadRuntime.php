<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Native thin-AOT str_pad — no NestedJIT StrPadJitHelper (#36388).
 *
 * Stale helper-runtime TUs still define leaky {@code __compiler_str_pad} (NestedJIT
 * concat/substr scratch in {@see \PHPCompiler\ext\standard\StrPadJitHelper}). Call
 * sites use {@code phpc_str_pad_r1} so this body always wins — peer
 * {@see StrReplaceRuntime} / {@see NumberFormatRuntime}.
 *
 * Always returns a freshly allocated {@code __string__*} (including the no-pad
 * early path via {@code __string__separate}) so FUNCCALL temps free on unset.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_pad)
 */
final class StrPadRuntime
{
    public const ABI = 'phpc_str_pad_r1';

    public const BRIDGE_ENTRY = 'str_pad_r1_bridge_entry';

    /** NestedJIT helper wording (no CompilerVersion under NestedJIT; #29755). */
    private const EMPTY_PAD_MSG = 'str_pad(): Argument #3 ($pad_string) must be a non-empty string';

    private static int $seq = 0;

    /**
     * Emit full pad bridge body (builder already at entry). Ends with returnValue.
     *
     * Params: (input, pad_length, pad_string, pad_type) — pad_type 0=LEFT 1=RIGHT 2=BOTH.
     */
    public static function emitBridgeBody(Context $context, LlvmFunction $fn): void
    {
        self::ensureDecls($context);
        ++self::$seq;
        $tag = 'sp_'.(string) self::$seq;

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $two = $i64->constInt(2, false);

        // Entry allocas before any branch (peer PregMatchRuntime / StrReplaceRuntime).
        $leftIPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $rightIPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);

        $input = $fn->getParam(0);
        $padLength = $fn->getParam(1);
        $padString = $fn->getParam(2);
        $padType = $fn->getParam(3);

        $inputLen = $context->builder->load($context->builder->structGep($input, $map['length']));
        $padLen = $context->builder->load($context->builder->structGep($padString, $map['length']));
        $inputData = $context->builder->structGep($input, $map['value']);
        $padData = $context->builder->structGep($padString, $map['value']);

        $nopadBb = $fn->appendBasicBlock($tag.'_nopad');
        $checkPadBb = $fn->appendBasicBlock($tag.'_chkpad');
        $workBb = $fn->appendBasicBlock($tag.'_work');

        $noPad = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $padLength, $zero),
            $context->builder->icmp(Builder::INT_SLE, $padLength, $inputLen)
        );
        $context->builder->branchIf($noPad, $nopadBb, $checkPadBb);

        $context->builder->positionAtEnd($nopadBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__separate'), $input)
        );

        $context->builder->positionAtEnd($checkPadBb);
        $chkInsert = BasicBlockHelper::tryGetInsertBlock($context);
        TypeErrorRaise::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $chkInsert);
        $emptyFail = $fn->appendBasicBlock($tag.'_emptypad_fail');
        $emptyOk = $fn->appendBasicBlock($tag.'_emptypad_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $padLen, $zero),
            $emptyOk,
            $emptyFail
        );
        $context->builder->positionAtEnd($emptyFail);
        TypeErrorRaise::emitValueError($context, self::EMPTY_PAD_MSG);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
        $context->builder->positionAtEnd($emptyOk);
        $context->builder->branch($workBb);

        $context->builder->positionAtEnd($workBb);
        $need = $context->builder->sub($padLength, $inputLen);
        $isBoth = $context->builder->icmp(Builder::INT_EQ, $padType, $two);
        $isLeft = $context->builder->icmp(Builder::INT_EQ, $padType, $zero);
        $leftNeedBoth = $context->builder->signedDiv($need, $two);
        $rightNeedBoth = $context->builder->sub($need, $leftNeedBoth);
        $leftNeed = $context->builder->select(
            $isBoth,
            $leftNeedBoth,
            $context->builder->select($isLeft, $need, $zero)
        );
        $rightNeed = $context->builder->select(
            $isBoth,
            $rightNeedBoth,
            $context->builder->select($isLeft, $zero, $need)
        );

        $out = $context->builder->call($context->lookupFunction('__string__alloc'), $padLength);
        $context->intrinsic->builder = $context->builder;
        $outData = $context->builder->structGep($out, $map['value']);

        self::fillRepeating(
            $context,
            $fn,
            $outData,
            $leftNeed,
            $padData,
            $padLen,
            $leftIPtr,
            $tag.'_L'
        );
        $atInput = $context->builder->gep($outData, $leftNeed);
        $context->intrinsic->memcpy($atInput, $inputData, $inputLen, false);
        $atRight = $context->builder->gep($atInput, $inputLen);
        self::fillRepeating(
            $context,
            $fn,
            $atRight,
            $rightNeed,
            $padData,
            $padLen,
            $rightIPtr,
            $tag.'_R'
        );

        $context->builder->returnValue($out);
    }

    /**
     * Write {@code length} bytes of repeating pad into {@code dest}.
     */
    private static function fillRepeating(
        Context $context,
        LlvmFunction $fn,
        Value $dest,
        Value $length,
        Value $padData,
        Value $padLen,
        Value $iPtr,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $context->builder->store($zero, $iPtr);

        $head = $fn->appendBasicBlock($tag.'_h');
        $body = $fn->appendBasicBlock($tag.'_b');
        $done = $fn->appendBasicBlock($tag.'_d');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $i, $length),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $srcOff = $context->builder->unsigendRem($i, $padLen);
        $byte = $context->builder->load($context->builder->gep($padData, $srcOff));
        $context->builder->store($byte, $context->builder->gep($dest, $i));
        $context->builder->store($context->builder->add($i, $one), $iPtr);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function ensureDecls(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        LibcExtern::ensureMemcpyImplemented($context);

        foreach (
            [
                '__string__alloc' => [$strPtr, false, [$i64]],
                '__string__separate' => [$strPtr, false, [$strPtr]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
                continue;
            } catch (\Throwable) {
            }
            $decl = $context->module->addFunction(
                $name,
                $context->context->functionType($ret, $vararg, ...$params)
            );
            $context->registerFunction($name, $decl);
        }
    }
}
