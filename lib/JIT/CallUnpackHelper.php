<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;

/**
 * JIT lowering for call-time ...$spread — thin trampoline to {@see CallUnpackCompileTime}
 * + {@see \PHPCompiler\VM\CallUnpackSupport} (#10202).
 */
final class CallUnpackHelper
{
    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     * @param list<string>                                                                $paramNames
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}|null
     */
    public static function tryResolveCompileTimeNamedUnpack(
        ?Block $block,
        array $argEntries,
        array $argOperands,
        array $paramNames,
        ?int $variadicIndex,
        JIT $jit,
        ?string $functionName = null
    ): ?array {
        return CallUnpackCompileTime::tryResolveCompileTimeNamedUnpack(
            $block,
            $argEntries,
            $argOperands,
            $paramNames,
            $variadicIndex,
            $jit,
            $functionName
        );
    }

    public static function tryCompileTimeArrayFromOperand(Block $block, Operand $operand): ?VmVariable
    {
        return CallUnpackCompileTime::tryCompileTimeArrayFromOperand($block, $operand);
    }
}
