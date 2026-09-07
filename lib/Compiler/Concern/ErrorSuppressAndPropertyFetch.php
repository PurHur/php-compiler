<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Config;
use PHPCompiler\JIT;
use PHPCompiler\VM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\Printer;
use PHPCompiler\Runtime;
use PHPCompiler\CompileResult;

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\DateTimeInterfaceSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\ClassReadonly;
use PHPCompiler\VM\ClassFinal;
use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Compiler\AbstractMethodBodyCheck;
use PHPCompiler\Compiler\AbstractMethodVisibilityCheck;
use PHPCompiler\Compiler\AbstractPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\InterfaceConstAmbiguityCheck;
use PHPCompiler\Compiler\InterfaceConstVisibilityCheck;
use PHPCompiler\Compiler\InterfaceMethodBodyCheck;
use PHPCompiler\Compiler\InterfaceMethodFinalCheck;
use PHPCompiler\Compiler\InterfaceMethodVisibilityCheck;
use PHPCompiler\Compiler\EnumAbstractMethodCompileCheck;
use PHPCompiler\Compiler\EnumBuiltinMethodRedeclareCheck;
use PHPCompiler\Compiler\ClassConstDuplicateCheck;
use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\Compiler\EnumMagicMethodCheck;
use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Compiler\MagicMethodArityCheck;
use PHPCompiler\Compiler\MagicMethodParamTypeCheck;
use PHPCompiler\Compiler\MagicMethodReturnTypeCheck;
use PHPCompiler\Compiler\MagicMethodStaticCheck;
use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCompiler\Compiler\DuplicateUnionMemberCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmSubsetCompileCheck;
use PHPCompiler\Compiler\RedundantObjectClassUnionCompileCheck;
use PHPCompiler\Compiler\IntersectionTypeMemberCompileCheck;
use PHPCompiler\Compiler\FunctionStaticAnonymousClassCompileCheck;
use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Compiler\NonAbstractMethodBodyCheck;
use PHPCompiler\Compiler\NonEnumBuiltinInterfaceCompileCheck;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\FinalClassConstCheck;
use PHPCompiler\Compiler\TraitClassConstConflictCheck;
use PHPCompiler\Compiler\FinalClassExtensionCheck;
use PHPCompiler\Compiler\ImplementsHierarchyCompileCheck;
use PHPCompiler\VM\ImplementsHierarchyRuntimeCheck;
use PHPCompiler\Compiler\FinalMethodOverrideCheck;
use PHPCompiler\Compiler\FinalPropertyOverrideCheck;
use PHPCompiler\Compiler\InterfaceImplementationCheck;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Compiler\GeneratorStaticMethodCompileCheck;
use PHPCompiler\Compiler\ReadonlyClassCompileCheck;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Compiler\TraitCollisionCheck;
use PHPCompiler\Compiler\ClassConstVisibilityInheritCheck;
use PHPCompiler\Compiler\PropertyVisibilityInheritCheck;
use PHPCompiler\Compiler\TypedClassConstInheritCheck;
use PHPCompiler\Compiler\TypedPropertyInheritCheck;
use PHPCompiler\Compiler\VariadicPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\Compiler\OverrideValidator;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

/**
 * Error-suppress primary resolution and property-fetch compile helpers.
 *
 * Extracted from {@see \PHPCompiler\Compiler} behind the opcode-corpus-md5 gate (#36403 / #36230).
 * Switch / isset / include / throw lowering lives in
 * {@see SwitchIssetIncludeAndThrowCompile} (#36387).
 */
trait ErrorSuppressAndPropertyFetch
{
    /**
     * Outermost `@` expression in php-cfg (last call/new/include before the jump).
     * Nested arg-eval calls are hoisted as earlier siblings and must not steal the return slot (#9332).
     */
    private function findErrorSuppressPrimaryInnerExpr(ErrorSuppressBlock $block): ?Op
    {
        $primary = null;
        foreach ($block->children as $child) {
            if ($this->isErrorSuppressInnerExpr($child)) {
                $primary = $child;
            }
        }

        return $primary;
    }

    private function isErrorSuppressInnerExpr(Op $child): bool
    {
        return $child instanceof Op\Expr\FuncCall
            || $child instanceof Op\Expr\NsFuncCall
            || $child instanceof Op\Expr\MethodCall
            || $child instanceof Op\Expr\StaticCall
            || $child instanceof Op\Expr\New_
            || $child instanceof Op\Expr\Include_
            || $child instanceof Op\Expr\ArrayDimFetch
            || $child instanceof Op\Expr\Isset_
            || $child instanceof Op\Expr\Empty_
            || $child instanceof Op\Expr\UnaryPlus
            || $child instanceof Op\Expr\UnaryMinus
            || $child instanceof Op\Expr\BinaryOp
            // `@$cv` materializes via Assign under silence (#13587 / #29132 / #31881).
            || $child instanceof Op\Expr\Assign;
    }

    /**
     * php-cfg may leave include result usages empty when the value feeds a FuncCall arg
     * (distinct Temporary for the call arg) or an {@see ErrorSuppressBlock} exit (#12163, #10336, #21938).
     */
    private function includeNeedsReturnSlot(Operand $result, Block $block): bool
    {
        if (!empty($result->usages)) {
            return true;
        }
        if ($block->callResultFeedsReturn($result) || $block->callResultFeedsEcho($result)) {
            return true;
        }
        if ($block->callResultFeedsErrorSuppressExit($result)) {
            return true;
        }
        if (null !== $block->orig && $block->orig instanceof ErrorSuppressBlock) {
            return true;
        }

        // var_export(require $f) / strlen(include $f): php-cfg usages stay empty (#21938).
        return $this->callResultFeedsInlineCallArg($result, $block);
    }

    private function findFuncCallExecReturnSlot(Block $block): ?int
    {
        return $block->lastFunccallExecReturnSlot();
    }

    /** TYPE_INCLUDE result slot (arg2) — `@include` / `@require` expression value (#21938). */
    private function findIncludeReturnSlot(Block $block): ?int
    {
        $last = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INCLUDE === $op->type && null !== $op->arg2) {
                $last = (int) $op->arg2;
            }
        }

        return $last;
    }

    private function bindErrorSuppressResultOperandUsages(
        Op $cfgOp,
        Block $endCompiled,
        Operand $suppressResult,
        int $slot
    ): void {
        if ($cfgOp instanceof Op\Expr\Assign && $this->assignIsPostSuppressIndependent($cfgOp, $endCompiled->orig)) {
            if ($cfgOp->expr instanceof Op) {
                $this->bindErrorSuppressResultOperandUsages($cfgOp->expr, $endCompiled, $suppressResult, $slot);
            }
            foreach ($cfgOp->children ?? [] as $child) {
                if ($child instanceof Op) {
                    $this->bindErrorSuppressResultOperandUsages($child, $endCompiled, $suppressResult, $slot);
                }
            }

            return;
        }
        if ($cfgOp instanceof Op\Expr) {
            if (property_exists($cfgOp, 'args') && is_array($cfgOp->args)) {
                foreach ($cfgOp->args as $arg) {
                    if ($arg instanceof Operand && $this->operandsReferToSameVariable($suppressResult, $arg)) {
                        $endCompiled->bindScopeSlot($arg, $slot);
                    }
                }
            }
            if (property_exists($cfgOp, 'var') && $cfgOp->var instanceof Operand) {
                if ($this->operandsReferToSameVariable($suppressResult, $cfgOp->var)) {
                    $endCompiled->bindScopeSlot($cfgOp->var, $slot);
                }
            }
            if (property_exists($cfgOp, 'expr') && $cfgOp->expr instanceof Operand) {
                if ($this->operandsReferToSameVariable($suppressResult, $cfgOp->expr)) {
                    $endCompiled->bindScopeSlot($cfgOp->expr, $slot);
                }
            }
        }
        foreach ($cfgOp->children ?? [] as $child) {
            if ($child instanceof Op) {
                $this->bindErrorSuppressResultOperandUsages($child, $endCompiled, $suppressResult, $slot);
            }
        }
    }

    /**
     * Assign from error_get_last() after END_SILENCE must not alias @ inner return slot (#16223).
     */
    private function assignRhsIsPostSuppressIndependentCall(Op\Expr\Assign $assign): bool
    {
        $expr = $assign->expr ?? null;
        if (!$expr instanceof Op\Expr\FuncCall && !$expr instanceof Op\Expr\NsFuncCall) {
            return false;
        }

        return $this->cfgOpIsPostSuppressIndependentCall($expr);
    }

    private function cfgOpIsPostSuppressIndependentCall(Op $op): bool
    {
        if (!$op instanceof Op\Expr\FuncCall && !$op instanceof Op\Expr\NsFuncCall) {
            return false;
        }

        return \in_array(
            $this->resolveCfgFuncCallName($op),
            [
                'error_get_last',
                'error_clear_last',
            ],
            true
        );
    }

    /**
     * php-cfg may hoist {@see error_get_last}() as a sibling stmt before the Assign (#16223).
     */
    private function assignIsPostSuppressIndependent(Op\Expr\Assign $assign, ?CfgBlock $endCfg): bool
    {
        if ($this->assignRhsIsPostSuppressIndependentCall($assign)) {
            return true;
        }
        if (null === $endCfg) {
            return false;
        }
        $expr = $assign->expr ?? null;
        if (!$expr instanceof Operand) {
            return false;
        }
        foreach ($endCfg->children as $child) {
            if (!$this->cfgOpIsPostSuppressIndependentCall($child) || !isset($child->result)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($expr, $child->result)) {
                return true;
            }
        }

        return false;
    }

    /** True when END_SILENCE block assigns error_get_last() immediately after @ (#16223). */
    private function endBlockAssignsErrorGetLastAfterSuppress(?CfgBlock $endCfg): bool
    {
        if (null === $endCfg) {
            return false;
        }
        foreach ($endCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && $this->assignIsPostSuppressIndependent($child, $endCfg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when no call in the post-@ block consumes the suppressed inner expression (#16223).
     *
     * Standalone `@f(); $x = error_get_last();` discards the @ return; slot inheritance must not
     * poison later statements still in the same php-cfg END_SILENCE block.
     */
    private function errorSuppressEndBlockInnerResultUnused(
        ?CfgBlock $endCfg,
        Block $endCompiled,
        Operand $suppressResult
    ): bool {
        if (null === $endCfg) {
            return false;
        }
        foreach ($endCfg->children as $child) {
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->callInErrorSuppressEndBlockUsesInnerResultAsArg($endCompiled, $child)
            ) {
                return false;
            }
        }
        foreach ($suppressResult->usages as $usage) {
            if (
                ($usage instanceof Op\Expr\FuncCall || $usage instanceof Op\Expr\NsFuncCall)
                && \in_array($usage, $endCfg->children, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /** True when END_SILENCE block reassigns the suppress result via error_get_last() (#16223). */
    private function endBlockHasPostSuppressIndependentAssign(?CfgBlock $endCfg, Operand $suppressResult): bool
    {
        if (null === $endCfg) {
            return false;
        }
        foreach ($endCfg->children as $child) {
            if (!$child instanceof Op\Expr\Assign || !$this->assignIsPostSuppressIndependent($child, $endCfg)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($suppressResult, $child->var)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skip cfg-root prebind when END_SILENCE immediately assigns error_get_last() to the suppress SSA (#16223).
     *
     * Keep prebind for nested `@f()` inside a sibling call (var_export(@get_cfg_var(...), true)).
     */
    private function shouldSkipPrebindCfgVarRootForSuppressResult(
        Block $endCompiled,
        ?CfgBlock $endCfg,
        Operand $suppressResult
    ): bool {
        if (null === $endCfg || !$this->endBlockHasPostSuppressIndependentAssign($endCfg, $suppressResult)) {
            return false;
        }
        foreach ($endCfg->children as $child) {
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->callInErrorSuppressEndBlockUsesInnerResultAsArg($endCompiled, $child)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Standalone `@f(); $x = error_get_last();` — @ return is discarded; skip slot inheritance (#16223).
     */
    private function errorSuppressEndBlockDiscardsInnerResultForErrorGetLast(Block $block): bool
    {
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return false;
        }
        $parentCfg = $endCfg->parents[0];
        if (!$parentCfg instanceof ErrorSuppressBlock) {
            return false;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null === $primary || !isset($primary->result)) {
            return false;
        }

        return $this->endBlockAssignsErrorGetLastAfterSuppress($endCfg)
            && $this->errorSuppressEndBlockInnerResultUnused($endCfg, $block, $primary->result);
    }

    /**
     * Emit a read fetch in $block (used by ?? left branch when the stmt fetch was skipped).
     */
    private function compilePropertyFetchRead(
        Op\Expr\PropertyFetch $fetch,
        Block $block,
        bool $propertyHookCoalesceRead = false
    ): void {
        $op = new OpCode(
            OpCode::TYPE_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true)
        );
        if ($propertyHookCoalesceRead) {
            $op->propertyHookCoalesceRead = true;
        }
        $block->addOpCode($op);
        if (null !== $op->arg1) {
            $fetchSlot = (int) $op->arg1;
            if (null !== $fetch->result) {
                $block->bindOperandScopeSlot($fetch->result, $fetchSlot);
            }
        }
        $this->syncPropertyFetchResultToFollowingFuncCallArg($fetch, $block);
    }

}
