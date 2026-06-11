<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;

/**
 * Compile-time / block-folded RoundingMode resolution for round() (#5934).
 *
 * Mirrors JitParseUrl::tryResolveComponent — AOT standalone lacks vmContext constantFetch.
 */
final class JitRoundModeResolve
{
    public static function tryResolveMode(
        Context $context,
        JITVariable $arg,
        ?Block $block = null,
        ?Operand $operand = null
    ): ?int {
        $fromJit = RoundingModeJit::compileTimeRoundMode($context, $arg);
        if (null !== $fromJit) {
            return $fromJit;
        }
        if (null !== $block && null !== $operand) {
            return self::tryResolveModeFromBlock($context, $block, $operand);
        }

        return null;
    }

    public static function tryResolveModeFromBlock(Context $context, Block $block, Operand $modeOp): ?int
    {
        $slot = self::operandSlot($block, $modeOp);
        if (null === $slot) {
            return null;
        }

        return self::slotRoundMode($context, $block, $slot, []);
    }

    /**
     * @param array<int, true> $visited
     */
    private static function slotRoundMode(Context $context, Block $block, int $slot, array $visited): ?int
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;

        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            $fromEnum = VmRoundMode::tryRoundModeInt($const);
            if (null !== $fromEnum) {
                return $fromEnum;
            }
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $const->type) {
                return $const->toInt();
            }
        }

        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                return self::modeFromClassConstFetch($context, $block, $op);
            }
        }

        return null;
    }

    private static function modeFromClassConstFetch(Context $context, Block $block, OpCode $op): ?int
    {
        $classOp = $block->getOperand($op->arg2);
        $nameOp = $block->getOperand($op->arg3);
        if (!$classOp instanceof Literal || !$nameOp instanceof Literal) {
            return null;
        }
        if (0 !== strcasecmp(ltrim((string) $classOp->value, '\\'), 'RoundingMode')) {
            return null;
        }

        return VmRoundMode::roundModeIntFromCaseName((string) $nameOp->value);
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
}
