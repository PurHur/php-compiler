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
 * Native thin-AOT chunk_split — no NestedJIT ChunkSplitJitHelper (#36388).
 *
 * Stale helper-runtime TUs still NestedJIT {@code __compiler_chunk_split} (leaky
 * FUNCCALL temps under thin AOT — OOM / RSS growth on short-lived results). Call
 * sites use {@code phpc_chunk_split_r1} so this body always wins — peer
 * {@see StrPadRuntime} / {@see StrReplaceRuntime}.
 *
 * Always returns a freshly allocated {@code __string__*} so FUNCCALL temps free
 * on unset. Empty input → copy of separator (php-src string.c).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class ChunkSplitRuntime
{
    public const ABI = 'phpc_chunk_split_r1';

    public const BRIDGE_ENTRY = 'chunk_split_r1_bridge_entry';

    private const LENGTH_ERROR = 'chunk_split(): Argument #2 ($length) must be greater than 0';

    private static int $seq = 0;

    /**
     * Emit full chunk_split bridge body (builder already at entry). Ends with returnValue.
     *
     * Params: (input, chunk_len, separator).
     */
    public static function emitBridgeBody(Context $context, LlvmFunction $fn): void
    {
        self::ensureDecls($context);
        ++self::$seq;
        $tag = 'cs_'.(string) self::$seq;

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        // Entry allocas before any branch (peer StrPadRuntime / StrReplaceRuntime).
        $posPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $outOffPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);

        $input = $fn->getParam(0);
        $chunkLen = $fn->getParam(1);
        $separator = $fn->getParam(2);

        $inputLen = $context->builder->load($context->builder->structGep($input, $map['length']));
        $sepLen = $context->builder->load($context->builder->structGep($separator, $map['length']));
        $inputData = $context->builder->structGep($input, $map['value']);
        $sepData = $context->builder->structGep($separator, $map['value']);

        $chkInsert = BasicBlockHelper::tryGetInsertBlock($context);
        TypeErrorRaise::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $chkInsert);

        $lenFail = $fn->appendBasicBlock($tag.'_len_fail');
        $lenOk = $fn->appendBasicBlock($tag.'_len_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $chunkLen, $one),
            $lenOk,
            $lenFail
        );
        $context->builder->positionAtEnd($lenFail);
        TypeErrorRaise::emitValueError($context, self::LENGTH_ERROR);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }

        $context->builder->positionAtEnd($lenOk);
        $emptyBb = $fn->appendBasicBlock($tag.'_empty');
        $workBb = $fn->appendBasicBlock($tag.'_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $inputLen, $zero),
            $emptyBb,
            $workBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $emptyOut = $context->builder->call($context->lookupFunction('__string__alloc'), $sepLen);
        $context->intrinsic->builder = $context->builder;
        $emptyData = $context->builder->structGep($emptyOut, $map['value']);
        $context->intrinsic->memcpy($emptyData, $sepData, $sepLen, false);
        $context->builder->returnValue($emptyOut);

        $context->builder->positionAtEnd($workBb);
        // num_chunks = ceil(input_len / chunk_len) = (input_len + chunk_len - 1) / chunk_len
        $numChunks = $context->builder->unsignedDiv(
            $context->builder->add($context->builder->sub($inputLen, $one), $chunkLen),
            $chunkLen
        );
        $outLen = $context->builder->add(
            $inputLen,
            $context->builder->mul($numChunks, $sepLen)
        );
        $out = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->intrinsic->builder = $context->builder;
        $outData = $context->builder->structGep($out, $map['value']);

        $context->builder->store($zero, $posPtr);
        $context->builder->store($zero, $outOffPtr);

        $head = $fn->appendBasicBlock($tag.'_h');
        $body = $fn->appendBasicBlock($tag.'_b');
        $done = $fn->appendBasicBlock($tag.'_d');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $pos, $inputLen),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $remain = $context->builder->sub($inputLen, $pos);
        $take = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $remain, $chunkLen),
            $remain,
            $chunkLen
        );
        $outOff = $context->builder->load($outOffPtr);
        $dest = $context->builder->gep($outData, $outOff);
        $src = $context->builder->gep($inputData, $pos);
        $context->intrinsic->memcpy($dest, $src, $take, false);
        $afterChunk = $context->builder->add($outOff, $take);
        $sepDest = $context->builder->gep($outData, $afterChunk);
        $context->intrinsic->memcpy($sepDest, $sepData, $sepLen, false);
        $context->builder->store($context->builder->add($pos, $take), $posPtr);
        $context->builder->store($context->builder->add($afterChunk, $sepLen), $outOffPtr);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($out);
    }

    private static function ensureDecls(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        LibcExtern::ensureMemcpyImplemented($context);

        foreach (
            [
                '__string__alloc' => [$strPtr, false, [$i64]],
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
