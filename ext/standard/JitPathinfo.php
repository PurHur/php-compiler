<?php

declare(strict_types=1);

/**
 * JIT/AOT helpers for pathinfo() (PATHINFO_* subset; mirrors VmString::pathinfo).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\StringPathinfo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPLLVM\Value;

final class JitPathinfo
{
    public static function invoke(Context $context, JITVariable $path, ?JITVariable $flags = null): Value
    {
        $pathVal = JitFilestatArg::lowerPathComponentFilename($context, $path, 'pathinfo', 0, 'path');
        $maskConst = 15;
        if (null !== $flags) {
            if (JITVariable::TYPE_NULL === $flags->type || ($flags->isNullConstant ?? false)) {
                self::emitNullFlagsDeprecation($context);
            }
            $resolved = self::tryResolveFlags($context, $flags);
            if (null === $resolved) {
                throw new \LogicException(
                    'pathinfo() flags must be a compile-time integer in this compiler build'
                );
            }
            $maskConst = $resolved;
        }
        $mask = $maskConst & 15;
        // php-src php_pathinfo(): options==0 → empty string (not empty array). #24941
        if (0 === $mask) {
            return $context->builder->load($context->constantStringFromString(''));
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

        return StringPathinfo::invokeComponent($context, $pathVal, $mask);
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
        // php-src: null flags deprecate+coerce to 0 (#24941)
        if (JITVariable::TYPE_NULL === $flags->type || ($flags->isNullConstant ?? false)) {
            return 0;
        }
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

    public static function extension(Context $context, Value $path): Value
    {
        return StringPathinfo::invokeExtension($context, $path);
    }

    public static function filename(Context $context, Value $path): Value
    {
        return StringPathinfo::invokeFilename($context, $path);
    }
}
