<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helpers for pathinfo() (PATHINFO_* subset; mirrors VmString::pathinfo).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitPathinfo
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable $path, ?JITVariable $flags = null): Value
    {
        $literal = $path->compileTimeString ?? null;
        $pathVal = JitStringBuiltinArg::lower($context, $path, 'pathinfo', 0, 'path');
        $maskConst = 15;
        if (null !== $flags) {
            $resolved = self::tryResolveFlags($context, $flags);
            if (null === $resolved) {
                throw new \LogicException(
                    'pathinfo() flags must be a compile-time integer in this compiler build'
                );
            }
            $maskConst = $resolved;
        }
        $mask = $maskConst & 15;
        if (0 === $mask) {
            return self::buildAllArray($context, []);
        }

        $literal = $path->compileTimeString ?? null;
        if (null !== $literal) {
            $result = VmString::pathinfo($literal, $mask);
            if (\is_array($result)) {
                return self::buildAllArray($context, $result);
            }

            return $context->builder->load($context->constantStringFromString((string) $result));
        }

        $bitCount = self::popcountMask($mask);
        if (1 === $bitCount) {
            if ($mask & 1) {
                return JitPath::dirname($context, $pathVal);
            }
            if ($mask & 2) {
                return JitPath::basename($context, $pathVal);
            }
            if ($mask & 4) {
                return self::extension($context, $pathVal);
            }

            return self::filename($context, $pathVal);
        }

        if (15 === $mask) {
            throw new \LogicException(
                'pathinfo() with PATHINFO_ALL requires a compile-time string path in this compiler build'
            );
        }

        return self::highestPriorityComponent($context, $pathVal, $mask);
    }

    /** php-src priority: dirname → basename → extension → filename. */
    private static function highestPriorityComponent(Context $context, Value $pathVal, int $mask): Value
    {
        if ($mask & 1) {
            return JitPath::dirname($context, $pathVal);
        }
        if ($mask & 2) {
            return JitPath::basename($context, $pathVal);
        }
        if ($mask & 4) {
            return self::extension($context, $pathVal);
        }

        return self::filename($context, $pathVal);
    }

    private static function popcountMask(int $mask): int
    {
        $count = 0;
        foreach ([1, 2, 4, 8] as $bit) {
            if ($mask & $bit) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $parts
     */
    private static function buildAllArray(Context $context, array $parts): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($parts as $key => $value) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            $valStr = $context->builder->load($context->constantStringFromString((string) $value));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                $valStr
            );
        }

        return $ht;
    }

    public static function tryResolveFlags(Context $context, JITVariable $flags): ?int
    {
        $constName = $flags->compileTimeConstantName ?? null;
        if (null !== $constName) {
            $lookup = strtolower($constName);
            if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                return StdlibConstants::CORE_INT_BY_NAME[$lookup];
            }
            if (null !== $context->runtime->vmContext) {
                $phpVar = $context->runtime->vmContext->constantFetch($constName);
                if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
                    return $phpVar->toInt();
                }
            }
        }

        if (JITVariable::TYPE_NATIVE_LONG === $flags->type
            && JITVariable::KIND_VALUE === $flags->kind
        ) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($flags->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($flags->value->value);
            }
        }

        return null;
    }

    /**
     * Resolve PATHINFO_* bitmask from CFG when JIT operands are boxed (issue #3772).
     */
    public static function tryResolveFlagsFromBlock(Context $context, Block $block, Operand $flagsOp): ?int
    {
        $slot = self::operandSlot($block, $flagsOp);
        if (null === $slot) {
            return null;
        }

        return self::slotPathinfoMask($context, $block, $slot, []);
    }

    /**
     * @param array<int, true> $visited
     */
    private static function slotPathinfoMask(Context $context, Block $block, int $slot, array $visited): ?int
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;

        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $const->type) {
                return $const->toInt() & 15;
            }
        }

        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                return self::maskFromConstFetch($context, $block, $op);
            }
            if (OpCode::TYPE_BITWISE_AND === $op->type
                || OpCode::TYPE_BITWISE_OR === $op->type
                || OpCode::TYPE_BITWISE_XOR === $op->type
            ) {
                $left = null !== $op->arg2 ? self::slotPathinfoMask($context, $block, $op->arg2, $visited) : null;
                $right = null !== $op->arg3 ? self::slotPathinfoMask($context, $block, $op->arg3, $visited) : null;
                if (null === $left || null === $right) {
                    return null;
                }

                return match ($op->type) {
                    OpCode::TYPE_BITWISE_AND => $left & $right,
                    OpCode::TYPE_BITWISE_OR => $left | $right,
                    OpCode::TYPE_BITWISE_XOR => $left ^ $right,
                    default => null,
                };
            }
        }

        return null;
    }

    private static function maskFromConstFetch(Context $context, Block $block, OpCode $op): ?int
    {
        $nameOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : $block->getOperand($op->arg2);
        if (!$nameOp instanceof Operand\Literal) {
            return null;
        }
        $lookup = strtolower((string) $nameOp->value);
        if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
            return StdlibConstants::CORE_INT_BY_NAME[$lookup];
        }
        if (null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch((string) $nameOp->value);
        if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
            return $phpVar->toInt();
        }

        return null;
    }

    private static function operandSlot(Block $block, Operand $op): ?int
    {
        foreach ($block->opCodes as $opcode) {
            foreach ([$opcode->arg1, $opcode->arg2, $opcode->arg3] as $slot) {
                if (null === $slot) {
                    continue;
                }
                try {
                    if ($block->getOperand($slot) === $op) {
                        return $slot;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    public static function extension(Context $context, Value $path): Value
    {
        $base = JitPath::basename($context, $path);

        return self::extensionFromBasename($context, $base);
    }

    public static function filename(Context $context, Value $path): Value
    {
        $base = JitPath::basename($context, $path);
        $ext = self::extensionFromBasename($context, $base);
        $map = $context->structFieldMap['__string__'];
        $baseLen = $context->builder->load(
            $context->builder->structGep($base, $map['length'])
        );
        $extLen = $context->builder->load(
            $context->builder->structGep($ext, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);

        $id = (string) (++self::$blockSerial);
        $done = BasicBlockHelper::append($context, 'pathinfo_fn_done_'.$id);
        $emptyExt = BasicBlockHelper::append($context, 'pathinfo_fn_noext_'.$id);
        $sliceBlock = BasicBlockHelper::append($context, 'pathinfo_fn_slice_'.$id);
        $extEmpty = $context->builder->icmp(Builder::INT_EQ, $extLen, $zero);
        $context->builder->branchIf($extEmpty, $emptyExt, $sliceBlock);

        $context->builder->positionAtEnd($emptyExt);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sliceBlock);
        $need = $context->builder->add($extLen, $one);
        $trimLen = $context->builder->sub($baseLen, $need);
        $tooShort = $context->builder->icmp(Builder::INT_SLE, $trimLen, $zero);
        $emptyFn = BasicBlockHelper::append($context, 'pathinfo_fn_empty_'.$id);
        $copyBlock = BasicBlockHelper::append($context, 'pathinfo_fn_copy_'.$id);
        $context->builder->branchIf($tooShort, $emptyFn, $copyBlock);

        $context->builder->positionAtEnd($emptyFn);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $emptyDone = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($copyBlock);
        $charPtr = $context->builder->structGep($base, $map['value']);
        $fnPart = string_trim::jitCopySlice($context, $base, $charPtr, $zero, $trimLen, 'pfn'.$id);
        $copyDone = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($base->typeOf());
        $phi->addIncoming($base, $emptyExt);
        $phi->addIncoming($emptyStr, $emptyDone);
        $phi->addIncoming($fnPart, $copyDone);

        return $phi;
    }

    private static function extensionFromBasename(Context $context, Value $base): Value
    {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($base, $map['length'])
        );
        $charPtr = $context->builder->structGep($base, $map['value']);
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);
        $dot = $context->getTypeFromString('int32')->constInt(ord('.'), false);

        $done = BasicBlockHelper::append($context, 'pathinfo_ext_done_'.$id);
        $emptyInput = BasicBlockHelper::append($context, 'pathinfo_ext_empty_'.$id);
        $scan = BasicBlockHelper::append($context, 'pathinfo_ext_scan_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $len, $zero),
            $emptyInput,
            $scan
        );

        $context->builder->positionAtEnd($emptyInput);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($scan);
        $startSlot = $context->builder->alloca($i64, 1, 'pathinfo_ext_start');
        $first = $context->builder->load($charPtr);
        $firstI32 = $context->builder->zExt($first, $context->getTypeFromString('int32'));
        $isLeadingDot = $context->builder->icmp(Builder::INT_EQ, $firstI32, $dot);
        $context->builder->store(
            $context->builder->select($isLeadingDot, $one, $zero),
            $startSlot
        );
        $lastSlot = $context->builder->alloca($i64, 1, 'pathinfo_ext_last');
        $context->builder->store(
            $context->builder->sub($len, $one),
            $lastSlot
        );
        $idxSlot = $context->builder->alloca($i64, 1, 'pathinfo_ext_idx');
        $context->builder->store($context->builder->sub($len, $one), $idxSlot);
        self::scanBackwardForDot($context, $charPtr, $startSlot, $idxSlot, $lastSlot, $id);

        $last = $context->builder->load($lastSlot);
        $start = $context->builder->load($startSlot);
        $noDot = BasicBlockHelper::append($context, 'pathinfo_ext_nodot_'.$id);
        $atEnd = BasicBlockHelper::append($context, 'pathinfo_ext_atend_'.$id);
        $sliceBlock = BasicBlockHelper::append($context, 'pathinfo_ext_slice_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $last, $zero),
            $noDot,
            $atEnd
        );

        $context->builder->positionAtEnd($noDot);
        $noDotStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($atEnd);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $last, $context->builder->sub($len, $one)),
            $noDot,
            $sliceBlock
        );

        $context->builder->positionAtEnd($sliceBlock);
        $extStart = $context->builder->add($last, $one);
        $extLen = $context->builder->sub($len, $extStart);
        $extStr = string_trim::jitCopySlice($context, $base, $charPtr, $extStart, $extLen, 'pext'.$id);
        $sliceDone = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($base->typeOf());
        $phi->addIncoming($emptyStr, $emptyInput);
        $phi->addIncoming($noDotStr, $noDot);
        $phi->addIncoming($extStr, $sliceDone);

        return $phi;
    }

    private static function scanBackwardForDot(
        Context $context,
        Value $charPtr,
        Value $startSlot,
        Value $idxSlot,
        Value $lastSlot,
        string $id
    ): void {
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);
        $dot = $context->getTypeFromString('int32')->constInt(ord('.'), false);

        $done = BasicBlockHelper::append($context, 'pathinfo_dot_done_'.$id);
        $head = BasicBlockHelper::append($context, 'pathinfo_dot_head_'.$id);
        $body = BasicBlockHelper::append($context, 'pathinfo_dot_body_'.$id);
        $found = BasicBlockHelper::append($context, 'pathinfo_dot_found_'.$id);
        $continueScan = BasicBlockHelper::append($context, 'pathinfo_dot_cont_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $start = $context->builder->load($startSlot);
        $stop = $context->builder->icmp(Builder::INT_SLT, $idx, $start);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $at = $context->builder->gep($charPtr, $idx);
        $ch = $context->builder->load($at);
        $chI32 = $context->builder->zExt($ch, $context->getTypeFromString('int32'));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $chI32, $dot);
        $context->builder->branchIf($isDot, $found, $continueScan);

        $context->builder->positionAtEnd($found);
        $context->builder->store($idx, $lastSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($continueScan);
        $context->builder->store($context->builder->sub($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
