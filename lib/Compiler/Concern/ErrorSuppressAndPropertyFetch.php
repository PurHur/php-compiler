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
     * Lower CFG switch to JUMPIF/EQUAL chain (JIT-safe; TYPE_CASE branchIf needs bool #96).
     */
    protected function compileSwitchAsJumpIfChain(Op\Stmt\Switch_ $switch, Block $block): void
    {
        if (!isset($switch->cond)) {
            $this->throwCompileLogic('Switch missing condition operand');
        }
        $condSlot = $this->requireOperandSlot(
            $this->compileOperand($switch->cond, $block, true),
            'switch condition'
        );
        $caseCount = count($switch->cases);
        if (0 === $caseCount) {
            $defaultOp = new OpCode(OpCode::TYPE_JUMP);
            $defaultOp->block1 = $this->compileCfgBranch($switch->default, $block);
            $block->addOpCode($defaultOp);

            return;
        }

        $current = $block;
        $savedSwitchJumpIfChain = $this->compilingSwitchJumpIfChain;
        $this->compilingSwitchJumpIfChain = true;
        for ($i = 0; $i < $caseCount; ++$i) {
            $eqSlot = $this->requireOperandSlot(
                $this->compileBoolTemporary($current),
                'switch equality temporary'
            );
            $caseSlot = $this->requireOperandSlot(
                $this->compileSwitchCaseOperand($switch->cases[$i], $current),
                'switch case #'.$i
            );
            $current->addOpCode(new OpCode(
                OpCode::TYPE_EQUAL,
                $eqSlot,
                $condSlot,
                $caseSlot
            ));

            $caseTarget = $this->compileCfgBranch($switch->targets[$i], $block);
            $isLast = $i === $caseCount - 1;
            if ($isLast) {
                $elseTarget = $this->compileCfgBranch($switch->default, $block);
            } else {
                $elseTarget = new Block($block->orig);
                $elseTarget->syntheticCfgBranch = true;
                $elseTarget->inheritUndefinedLocals = true;
                $elseTarget->inheritScopeFrom($current);
                $this->inheritFuncFromParent($elseTarget, $block);
            }

            $jump = new OpCode(OpCode::TYPE_JUMPIF, $eqSlot);
            $jump->block1 = $caseTarget;
            $jump->block2 = $elseTarget;
            $current->addOpCode($jump);
            $caseTarget->parents[] = $current;
            $elseTarget->parents[] = $current;
            if (!$isLast) {
                $current = $elseTarget;
            }
        }
        $this->compilingSwitchJumpIfChain = $savedSwitchJumpIfChain;
    }

    /**
     * Materialize switch case labels at runtime — php-cfg Switch_ cases may lack preceding fetches (#8767).
     */
    protected function compileSwitchCaseOperand(Operand $caseOperand, Block $block): ?int
    {
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                if ($child->result !== $caseOperand && !$this->operandsReferToSameVariable($child->result, $caseOperand)) {
                    continue;
                }
                foreach ($this->compileClassConstFetch($child, $block) as $op) {
                    $block->addOpCode($op);
                }

                return $this->compileOperand($caseOperand, $block, true);
            }
        }

        return $this->compileOperand($caseOperand, $block, true);
    }

    private const ISSET_EXPRESSION_COMPILE_ERROR =
        'Cannot use isset() on the result of an expression (you can use "null !== expression" instead)';

    /** Empty `[]` offset in read context — Zend/zend_language_parser.y (#12303). */
    private const ARRAY_EMPTY_OFFSET_READ_COMPILE_ERROR = 'Cannot use [] for reading';

    /**
     * Zend zend_compile.c zend_is_variable(): isset() operands must be variables, dims, or properties (#8802).
     */
    protected function assertIssetVariableOperand(Operand $operand, Block $block): void
    {
        if (null !== $this->findCoalescePropertyFetch($operand, $block)) {
            return;
        }
        if (null !== $this->findCoalesceStaticPropertyFetch($operand, $block)) {
            return;
        }
        if (null !== $this->findCoalesceArrayDimFetch($operand, $block)) {
            return;
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return;
        }
        if (null !== $this->unwrapStaticPropertyFetch($operand)) {
            return;
        }

        $this->throwCompileError(self::ISSET_EXPRESSION_COMPILE_ERROR);
    }

    /**
     * @return OpCode[]
     */
    protected function compileIsset(Op\Expr\Isset_ $expr, Block $block): array
    {
        assert(1 === count($expr->vars));
        $nullsafeChain = $this->collectNullsafePropertyFetchChain($expr->vars[0], $block);
        if ([] !== $nullsafeChain) {
            $this->compileIssetNullsafePropertyFetchChain($nullsafeChain, $expr, $block);

            return [];
        }
        $this->assertIssetVariableOperand($expr->vars[0], $block);
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $propFetch = $this->findCoalescePropertyFetch($expr->vars[0], $block);
        $staticPropFetch = null !== $propFetch
            ? null
            : $this->findCoalesceStaticPropertyFetch($expr->vars[0], $block);
        $dimFetch = null !== $propFetch || null !== $staticPropFetch
            ? null
            : $this->findCoalesceArrayDimFetch($expr->vars[0], $block);
        if (null !== $dimFetch) {
            $chain = $this->collectArrayDimFetchChain($dimFetch, $block);
            foreach ($chain as $chainFetch) {
                $this->rejectArrayEmptyOffsetRead($chainFetch, $block);
            }
            [$prefixOps, $containerSlot] = $this->emitQuietDimFetchChainPrefix($chain, $block);
            $lastFetch = $chain[count($chain) - 1];
            $dimSlot = null !== $lastFetch->dim
                ? $this->compileOperand($lastFetch->dim, $block, true)
                : null;
            $issetOp = $this->makeIssetOpCode($resultSlot, $containerSlot, $dimSlot, false);
            $prefixOps[] = $issetOp;

            return $prefixOps;
        }
        [$containerSlot, $dimSlot] = null !== $propFetch
            ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $block)
            : (null !== $staticPropFetch
                ? $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block)
                : $this->resolveIssetTarget($expr->vars[0], $block));
        if (null === $containerSlot) {
            $varSlot = $this->compileOperand($expr->vars[0], $block, true);

            return [new OpCode(OpCode::TYPE_ISSET, $resultSlot, $varSlot, null)];
        }

        $issetOp = $this->makeIssetOpCode($resultSlot, $containerSlot, $dimSlot, null !== $propFetch);
        if (null !== $staticPropFetch) {
            $issetOp->issetOnStaticProperty = true;
        }

        return [$issetOp];
    }

    protected function compileIncludeOp(Op\Expr\Include_ $expr, Block $block): OpCode
    {
        // Include expression value is independent of the enclosing function return type
        // (void/never blocks must still materialize require/include results for call args) (#21938).
        $resultSlot = null;
        if (isset($expr->result) && $this->includeNeedsReturnSlot($expr->result, $block)) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
        }

        $sourceFile = $expr->getFile() ?? '';
        $includeKind = match ($expr->type) {
            Op\Expr\Include_::TYPE_INCLUDE => OpCode::INCLUDE_KIND_INCLUDE,
            Op\Expr\Include_::TYPE_INCLUDE_ONCE => OpCode::INCLUDE_KIND_INCLUDE_ONCE,
            Op\Expr\Include_::TYPE_REQUIRE => OpCode::INCLUDE_KIND_REQUIRE,
            Op\Expr\Include_::TYPE_REQUIRE_ONCE => OpCode::INCLUDE_KIND_REQUIRE_ONCE,
            default => OpCode::INCLUDE_KIND_INCLUDE_ONCE,
        };

        $deploySpec = ConstStringFolder::tryParseDeployInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $deploySpec) {
            $pathIndex = count($block->deployIncludePaths);
            $block->deployIncludePaths[$pathIndex] = $deploySpec;
            $compilePath = $deploySpec['compile'] ?? '';
            $pathOperand = new Operand\Literal('' !== $compilePath ? $compilePath : ' ');
            $pathOperand->type = Type::string();

            $op = new OpCode(
                OpCode::TYPE_INCLUDE,
                $this->compileOperand($pathOperand, $block, true),
                $resultSlot,
                $pathIndex,
            );
            $op->includeKind = $includeKind;
            $block->emittedIncludeOrEvalExprIds[spl_object_id($expr)] = true;

            return $op;
        }

        $includePath = ConstStringFolder::foldForInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $includePath) {
            $resolved = IncludePathResolver::resolve($includePath, $expr->getFile());
            if (null !== $resolved) {
                $this->markCallerLocalsUsedByLiteralInclude($resolved, $block);
                $literal = new Operand\Literal($resolved);
                $literal->type = Type::string();
                $pathIndex = count($block->literalIncludePaths);
                $block->literalIncludePaths[$pathIndex] = $resolved;

                $op = new OpCode(
                    OpCode::TYPE_INCLUDE,
                    $this->compileOperand($literal, $block, true),
                    $resultSlot,
                    $pathIndex,
                );
                $op->includeKind = $includeKind;
                $block->emittedIncludeOrEvalExprIds[spl_object_id($expr)] = true;

                return $op;
            }
        }

        $op = new OpCode(
            OpCode::TYPE_INCLUDE,
            $this->compileOperand($expr->expr, $block, true),
            $resultSlot,
        );
        $op->includeKind = $includeKind;
        $block->emittedIncludeOrEvalExprIds[spl_object_id($expr)] = true;

        return $op;
    }

    /**
     * php-cfg emits inner expr ops (New_, …) before Throw_; lower them inside compileExpr(Throw_) (#3802).
     *
     * @param Op[] $ops
     */
    private function isLoweredByFollowingThrow(Op $op, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr) {
            return false;
        }
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Throw_) {
                return $this->exprOpFeedsThrowOperand($op, $next);
            }
            if (!$next instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private function exprOpFeedsThrowOperand(Op\Expr $op, Op\Expr\Throw_ $throw): bool
    {
        return $this->operandsChainEqual($op->result, $throw->expr);
    }

    /**
     * Ops after throw-expr in the same CFG block are unreachable (?: arm, &&/|| RHS, = throw …) (#3802).
     *
     * @param Op[] $ops
     */
    private function isUnreachableAfterThrow(Op $op, array $ops, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; --$j) {
            if ($ops[$j] instanceof Op\Expr\BinaryOp\Coalesce) {
                // ?? RHS throw is lowered on the coalesce branch; following stmts stay reachable (#9447).
                return false;
            }
            if ($ops[$j] instanceof Op\Expr\Throw_) {
                return true;
            }
            if (!$ops[$j] instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    /**
     * php-cfg emits `Throw_` then `Isset_(throw.result)` for `isset(throw …)`.
     * Without a look-ahead, isUnreachableAfterThrow skips Isset_ and the throw runs (#29086).
     *
     * @param Op[] $ops
     */
    private function throwResultFeedsFollowingIsset(Op\Expr\Throw_ $throw, array $ops, int $index): bool
    {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Isset_) {
                foreach ($next->vars as $var) {
                    if ($this->operandsChainEqual($throw->result, $var)) {
                        return true;
                    }
                }

                return false;
            }
            if (!$next instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private function findThrowInnerExprOp(Op\Expr\Throw_ $throw, Block $block): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($throw->expr);
        if ($root instanceof Op\Expr) {
            return $root;
        }

        return $this->findOrigExprOpForOperand($throw->expr, $block);
    }

    /**
     * @return list<OpCode>
     */
    private function compileThrowExpression(Op\Expr\Throw_ $expr, Block $block, Block ...$extraSearchBlocks): array
    {
        if ($this->isBareRethrowExpression($expr, $block, ...$extraSearchBlocks)) {
            return [new OpCode(OpCode::TYPE_RETHROW)];
        }

        $newOp = $this->findNewExprForThrowOperand($expr, $block, ...$extraSearchBlocks);
        $ops = [];
        $throwSlot = null;
        $throwEmitBlock = null;
        if (null !== $newOp) {
            foreach ($this->compileNewExprForThrow($newOp, $block) as $innerOpcode) {
                $ops[] = $innerOpcode;
            }
            $throwSlot = $this->compileOperand($newOp->result, $block, true);
        } else {
            $innerOp = $this->findThrowInnerExprOp($expr, $block);
            if (null !== $innerOp) {
                if ($innerOp instanceof Op\Expr\BinaryOp\Coalesce) {
                    // ?? merge must complete before TYPE_THROW; compileExpr(Coalesce) leaves throw on entry block (#15315).
                    $throwEmitBlock = $this->compileCoalesce($innerOp, $block);
                } else {
                    foreach ($this->compileExpr($innerOp, $block) as $innerOpcode) {
                        $ops[] = $innerOpcode;
                    }
                }
            }
        }
        $slotBlock = $throwEmitBlock ?? $block;
        if (null === $throwSlot) {
            $throwSlot = $this->compileOperand($expr->expr, $slotBlock, true);
        }
        $line = $expr->getLine();
        $throwOp = new OpCode(
            OpCode::TYPE_THROW,
            $throwSlot,
            $line > 0 ? $line : null
        );
        if (null !== $throwEmitBlock) {
            $throwEmitBlock->addOpCode($throwOp);

            return [];
        }
        $ops[] = $throwOp;

        return $ops;
    }

    private function findNewExprForThrowOperand(Op\Expr\Throw_ $throw, Block ...$searchBlocks): ?Op\Expr\New_
    {
        foreach ($searchBlocks as $searchBlock) {
            if (null === $searchBlock->orig) {
                continue;
            }
            foreach ($searchBlock->orig->children as $child) {
                if ($child instanceof Op\Expr\New_ && $this->operandsChainEqual($child->result, $throw->expr)) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * @return list<OpCode>
     */
    private function compileNewExprForThrow(Op\Expr\New_ $expr, Block $block): array
    {
        $this->rejectPseudoClassNewOutsideClassScope($expr, $block);
        // Same as Op\Expr\New_:: class path — defer abstract/enum instantiate to runtime (#25787).
        $className = $this->literalScopeClassName($expr->class);
        $resultSlot = $block->forceFreshVarSlot($expr->result);
        $mergeEcho = $this->mergeEchoSlotForBranch($block);
        if (null !== $mergeEcho && $resultSlot === $mergeEcho) {
            $resultSlot = $block->forceFreshVarSlot($expr->result);
        }
        $line = $expr->getLine();
        $return = [
            new OpCode(
                OpCode::TYPE_NEW,
                $resultSlot,
                $this->compileOperand($expr->class, $block, true),
                $line > 0 ? $line : null
            ),
        ];
        foreach ($this->compileCallArgSends($expr->args, $block, $className, $expr) as $send) {
            $return[] = $send;
        }
        $return[] = $this->compileFuncCallExecOpcode(
            $expr->result,
            $block,
            $line > 0 ? $line : 0
        );

        return $return;
    }

    private function compileOrigExprForOperand(Operand $operand, Block $block): void
    {
        $exprOp = $this->findOrigExprOpForOperand($operand, $block);
        if (null === $exprOp) {
            return;
        }
        $this->compileDeferredCoalesceBranchExpr($exprOp, $block);
    }

    private function findOrigExprOpForOperand(Operand $operand, Block $block): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr) {
            return $root;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr && $this->operandsChainEqual($child->result, $operand)) {
                return $child;
            }
        }

        return null;
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
