<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;

/**
 * Native-call param constraints, defaults, and runtime-`new` init fragments (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code paramTypeConstraintsForNativeCall}
 * through {@code compareOperandsForSlotResolution} so the hub keeps shrinking toward
 * gen-0 split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c ARG_RECV / typed parameter binding and
 * Zend/zend_API.c default-arg materialization — move-only Concern extract;
 * no new C ABI and no opcode/IR shape change.
 */
trait ParamConstraintsAndRuntimeNewInit
{
    /**
     * LLVM argument index => VM type constraint.
     *
     * @return array<int, int>
     */
    private function paramTypeConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            $isVariadic = null !== $block->variadicParamIndex && $paramIdx === $block->variadicParamIndex;
            if ($isVariadic) {
                if (
                    isset($block->paramVariadicElementIntersectionConstraints[$slot])
                    || isset($block->paramVariadicElementDnfConstraints[$slot])
                ) {
                    continue;
                }
                if (!isset($block->paramVariadicElementTypeConstraints[$slot])) {
                    continue;
                }
                $constraints[$paramIdx + $offset] = $block->paramVariadicElementTypeConstraints[$slot];
                continue;
            }
            if (
                isset($block->paramIntersectionConstraints[$slot])
                || isset($block->paramDnfConstraints[$slot])
            ) {
                continue;
            }
            if (!isset($block->paramTypeConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramTypeConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * @return array<int, true>
     */
    private function paramImplicitNullableForNativeCall(Block $block): array
    {
        $implicit = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            if (!isset($block->paramImplicitNullable[$slot])) {
                continue;
            }
            $implicit[(int) $op->arg2 + $offset] = true;
        }

        return $implicit;
    }

    /**
     * @return array<int, list<string>>
     */
    private function paramIntersectionConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            $isVariadic = null !== $block->variadicParamIndex && $paramIdx === $block->variadicParamIndex;
            if ($isVariadic) {
                if (!isset($block->paramVariadicElementIntersectionConstraints[$slot])) {
                    continue;
                }
                $constraints[$paramIdx + $offset] = $block->paramVariadicElementIntersectionConstraints[$slot];
                continue;
            }
            if (!isset($block->paramIntersectionConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramIntersectionConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * @return array<int, string>
     */
    private function paramClassConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            if (!isset($block->paramClassConstraints[$slot])) {
                continue;
            }
            $constraint = $block->paramClassConstraints[$slot];
            // Trait flatten sets traitComposingClassName — bind `parent` like VM (#31747).
            if ('parent' === strtolower(ltrim($constraint, '\\'))) {
                try {
                    $constraint = $this->resolveJitStaticScopeClass(
                        $block,
                        new Operand\Literal('parent')
                    );
                } catch (\Throwable) {
                    // Keep lexical keyword when composing parent is unavailable.
                }
            }
            $constraints[$paramIdx + $offset] = $constraint;
        }

        return $constraints;
    }

    /**
     * @return array<int, list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>>
     */
    private function paramDnfConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            $isVariadic = null !== $block->variadicParamIndex && $paramIdx === $block->variadicParamIndex;
            if ($isVariadic) {
                if (!isset($block->paramVariadicElementDnfConstraints[$slot])) {
                    continue;
                }
                $constraints[$paramIdx + $offset] = $block->paramVariadicElementDnfConstraints[$slot];
                continue;
            }
            if (!isset($block->paramDnfConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramDnfConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * LLVM argument index => by-reference formal (issue #3161, #140).
     *
     * @return array<int, true>
     */
    private function paramByRefForNativeCall(Block $block): array
    {
        $refs = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            if (!isset($block->paramByRef[(int) $op->arg2])) {
                continue;
            }
            $refs[(int) $op->arg2 + $offset] = true;
        }

        return $refs;
    }

    /**
     * Optional-param defaults as {@see VM\Variable} recipes — not lowered LLVM Values.
     *
     * Call sites rematerialize via {@see JIT\Call\Native::materializeDefaultArg()} so empty
     * array / null / string defaults are not reused across functions (Nyholm Response::__construct
     * `[]`/`null` → parentless `__hashtable__alloc` / dominate-fail under Slim AOT, #36382).
     *
     * @return array<int, VM\Variable>
     */
    private function collectParamDefaults(Block $block): array {
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if ($op->type !== OpCode::TYPE_ARG_RECV) {
                continue;
            }
            if (null === $op->arg3) {
                continue;
            }
            if (null !== $block->variadicParamIndex && $block->variadicParamIndex === (int) $op->arg2) {
                continue;
            }
            if (!isset($block->constants[$op->arg3])) {
                continue;
            }
            $defaultIdx = $op->arg2;
            if ($this->instanceMethodUsesThis($block)) {
                ++$defaultIdx;
            }
            $defaults[$defaultIdx] = $block->constants[$op->arg3];
        }
        return $defaults;
    }

    /**
     * Promoted ctor params with `new` defaults — property initialized at allocate() (#6652).
     *
     * @return array<int, array{prop: string, declClass: string}> LLVM arg index => promoted property meta
     */
    private function collectPromotedRuntimeNewDefaultProps(Block $block): array
    {
        if (!$this->instanceMethodUsesThis($block)) {
            return [];
        }
        $classId = $this->context->scope->classId;
        $declClass = ltrim($this->context->scope->className, '\\');
        $thisParamOffset = $this->llvmThisParamOffset($block);
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $paramIdx = (int) $op->arg2;
            if (null !== $block->variadicParamIndex && $block->variadicParamIndex === $paramIdx) {
                continue;
            }
            if (!isset($block->paramRuntimeDefaultInitBlocks[$paramIdx])) {
                continue;
            }
            $propName = $block->paramNames[$paramIdx] ?? null;
            if (!is_string($propName) || '' === $propName) {
                continue;
            }
            if ($classId >= 0) {
                $initBlock = $block->paramRuntimeDefaultInitBlocks[$paramIdx];
                $newClass = $this->jitPropertyNewClassNameFromOps($initBlock, $initBlock->opCodes);
                if (null !== $newClass) {
                    $this->context->type->object->definePropertyRuntimeNewDefault(
                        $classId,
                        $propName,
                        $newClass
                    );
                    $this->context->type->object->definePropertyRuntimeNewInitFragment(
                        $classId,
                        $propName,
                        $initBlock,
                        $block->paramRuntimeDefaultResultSlots[$paramIdx]
                            ?? throw new \LogicException('Missing runtime parameter default result slot')
                    );
                }
            }
            $defaults[$paramIdx + $thisParamOffset] = ['prop' => $propName, 'declClass' => $declClass];
        }

        return $defaults;
    }

    /**
     * Lower a property/param `new` init fragment at the current insert point (#3391, #6652).
     */
    public function jitVariableFromRuntimeNewInitFragment(Block $initBlock, int $resultSlot): Variable
    {
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'runtime_new_init');
        $func = JIT\BasicBlockHelper::parentFunction($this->context);
        $entry = $func->appendBasicBlock('runtime_new_init_entry');
        $cont = $func->appendBasicBlock('runtime_new_init_cont');
        $this->context->builder->branch($entry);
        $savedToCall = $this->context->scope->toCall;
        $savedArgs = $this->context->scope->args;
        $savedArgOperands = $this->context->scope->argOperands;
        $savedPreserveNew = $this->context->scope->preserveNewResultOnNullCall;
        $saved = $initBlock->syntheticCfgBranch ?? false;
        $initBlock->syntheticCfgBranch = true;
        try {
            $tail = $this->compileSubBlockAtEntry($func, $initBlock, $entry);
        } finally {
            $initBlock->syntheticCfgBranch = $saved;
            $this->context->scope->toCall = $savedToCall;
            $this->context->scope->args = $savedArgs;
            $this->context->scope->argOperands = $savedArgOperands;
            $this->context->scope->preserveNewResultOnNullCall = $savedPreserveNew;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'runtime_new_init_done');
        $this->context->builder->positionAtEnd($tail);
        $var = $this->variableFromBlockSlot($initBlock, $resultSlot);
        $this->context->builder->branch($cont);
        $this->context->builder->positionAtEnd($cont);

        return $var;
    }


    private static function operandSlotRank(\PHPCfg\Operand $op): int
    {
        $name = JIT\OperandName::resolve($op);
        if ($op instanceof \PHPCfg\Operand\Temporary && null !== $name && '' !== $name) {
            return 3;
        }
        if ($op instanceof \PHPCfg\Operand\Variable) {
            return 2;
        }

        return 1;
    }

    private static function compareOperandsForSlotResolution(\PHPCfg\Operand $a, \PHPCfg\Operand $b): int
    {
        return self::operandSlotRank($b) <=> self::operandSlotRank($a);
    }

}
