<?php

namespace PHPCompiler\Compiler\Concern;

use SplObjectStorage;
use PHPCompiler\Block;
use PHPCompiler\Func;
use PHPCompiler\OpCode;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\DnfType;
use PHPCompiler\Func\PHP as FuncPHP;
use PHPCompiler\VM\Variable;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * compileFunc, return-type application, CFG branch lowering, and global-import slots (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers Func→Block entry, declared/implicit return-type constraints (void/never/DNF/
 * __toString), compileCfgBlock/Branch + sibling CV slot inheritance, and
 * global $name / simple-variable name resolution.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait CompileFuncCfgReturnAndGlobalImport
{
    public function compileFunc(string $name, CfgFunc $func): Func {
        $this->resetCompileAbortDetail();
        $this->classCompileRegistry = new ClassCompileRegistry();
        $this->attributeClassRegistry = new AttributeClassRegistry();
        $this->seen = new SplObjectStorage;

        $funcBlock = $this->compileCfgBlock($func->cfg, $func->params, $func);
        $this->seen = null;
        return new FuncPHP($name, $funcBlock);
    }

    protected function applyReturnTypeFromFunc(Block $block, CfgFunc $func): void
    {
        // php-cfg marks file-level {main} as void; only enforce on user functions/methods (#205).
        if ('{main}' === $func->name && null === $func->class) {
            return;
        }
        $returnType = $func->returnType;
        // php-cfg represents “no return type” as null or Mixed_; Zend auto-declares `: string`
        // for untyped `__toString` (zend_compile.c / #26402).
        if (null === $returnType || $returnType instanceof Op\Type\Mixed_) {
            if ($this->applyImplicitToStringStringReturn($block, $func)) {
                return;
            }
            if (null === $returnType) {
                return;
            }
            // Untyped (Mixed_) non-__toString: no scalar constraint.
            $block->returnDeclaredType = $returnType;

            return;
        }
        $this->rejectPseudoClassTypeHintOutsideClassScope($returnType, $block, $func);
        $this->rejectParentTypeHintWithoutParent($returnType);
        $this->assertIntersectionTypeMembers($returnType);
        // void before never — Zend prefers "Void can only…" when both appear in a union (#26517).
        $this->assertFunctionSignatureVoidType($returnType);
        $this->assertFunctionSignatureNeverType($returnType);
        $this->assertMixedTypeRules($returnType);
        $block->returnDeclaredType = $returnType;
        if ($returnType instanceof Op\Type\Void_) {
            $block->returnTypeVoid = true;

            return;
        }
        if ($returnType instanceof Op\Type\Never_) {
            $block->returnTypeNever = true;

            return;
        }
        if ($returnType instanceof Op\Type\Reference) {
            $refName = $this->staticNameFromOperand($returnType->declaration);
            if ('static' === strtolower((string) $refName)) {
                $block->returnTypeStatic = true;

                return;
            }
            if (null !== $refName && '' !== $refName) {
                $resolved = $this->resolveTypeHintClassName($refName, $block);
                if (null !== $resolved && '' !== $resolved) {
                    $block->returnTypeConstraint = Variable::TYPE_OBJECT;
                    $block->returnClassConstraint = $resolved;
                    // Zend TypeError prints the resolved class for self/parent (zend_execute_API.c);
                    // keep unresolved trait `parent` as the keyword (#29911, #29912).
                    $block->returnDeclaredTypeLabel = ltrim($resolved, '\\');
                }

                return;
            }
        }
        if ($this->cfgTypeUsesDnfShape($returnType)) {
            $dnfArms = DnfType::armsFromCfgType(
                $returnType,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t, $block),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t, $block),
                fn (Op\Type\Reference $t) => $this->resolvedDnfReferenceNameFromCfgType($t, $block)
            );
            if (DnfType::hasConstraints($dnfArms)) {
                $block->returnDnfConstraints = $dnfArms;

                return;
            }
        }
        if ($returnType instanceof Op\Type\Literal) {
            if ('void' === $returnType->name) {
                $block->returnTypeVoid = true;

                return;
            }
            if ('never' === $returnType->name) {
                $block->returnTypeNever = true;

                return;
            }
            if ('static' === $returnType->name) {
                $block->returnTypeStatic = true;

                return;
            }
            if ('mixed' === strtolower($returnType->name)) {
                // Explicit `: mixed` is not untyped — fall-off / bare `return;` must error (#26485).
                $block->returnTypeMixed = true;
                $block->returnDeclaredTypeLabel = 'mixed';

                return;
            }
            $returnLc = strtolower($returnType->name);
            if ('true' === $returnLc || 'false' === $returnLc) {
                $block->returnTypeConstraint = Variable::TYPE_BOOLEAN;
                $block->returnLiteralBoolType = $returnLc;

                return;
            }
            // Bare `: callable` — Variable::mapFromType has no TYPE_CALLABLE mapping, so
            // without DNF arms zend_verify_return_type is skipped (#29887). Reuse the same
            // literal-arm path as ?callable / callable|… unions (DnfCheck / DnfParamCheck).
            if ('callable' === $returnLc) {
                $block->returnDnfConstraints = [['kind' => 'literal', 'name' => 'callable']];

                return;
            }
            // Bare `: iterable` — mapFromType treats it as a class name, so returns reject
            // arrays and TypeError says "iterable". Use the iterable DNF arm (array|Traversable
            // match) with Zend TypeError display Traversable|array (#29888 / #4829 sibling).
            if ('iterable' === $returnLc) {
                $block->returnDnfConstraints = [
                    ['kind' => 'literal', 'name' => 'iterable', 'display' => 'Traversable|array'],
                ];
                $block->returnDeclaredTypeLabel = 'iterable';

                return;
            }
            $declType = Type::fromDecl($returnType->name);
            $mapped = Variable::mapFromType($declType);
            if (Variable::TYPE_OBJECT === $mapped) {
                $className = '' !== (string) $declType->userType ? $declType->userType : $returnType->name;
                $block->returnTypeConstraint = Variable::TYPE_OBJECT;
                $block->returnClassConstraint = $className;
                $block->returnDeclaredTypeLabel = ltrim($className, '\\');

                return;
            }
            if (Variable::TYPE_UNDEFINED !== $mapped) {
                $block->returnTypeConstraint = $mapped;
            }
        }
    }

    /**
     * php-src: Zend/zend_compile.c — untyped `__toString` is compiled as returning string.
     * Enables zend_verify_return_type under strict_types (#26402); Reflection sees `: string`.
     *
     * @return bool true when the implicit string return was applied
     */
    private function applyImplicitToStringStringReturn(Block $block, CfgFunc $func): bool
    {
        $stringType = $this->implicitToStringReturnType($func);
        if (null === $stringType) {
            return false;
        }
        $block->returnDeclaredType = $stringType;
        $block->returnTypeConstraint = Variable::TYPE_STRING;

        return true;
    }

    /**
     * Zend auto-declares `: string` when `__toString` has no user-written return type.
     * php-cfg represents that absence as {@see Op\Type\Mixed_}.
     */
    private function implicitToStringReturnType(CfgFunc $func): ?Op\Type\Literal
    {
        if ('__tostring' !== strtolower($func->name)) {
            return null;
        }
        $returnType = $func->returnType;
        if (null !== $returnType && !($returnType instanceof Op\Type\Mixed_)) {
            return null;
        }

        return new Op\Type\Literal('string');
    }

    /**
     * php-cfg appends a null Terminal_Return after exit(); skip it for :never (issue #1358).
     */
    protected function neverFunctionHasAbnormalExitBeforeReturn(CfgBlock $block, Op\Terminal\Return_ $return): bool
    {
        foreach ($block->children as $child) {
            if ($child === $return) {
                return false;
            }
            if ($child instanceof Op\Expr\Exit_) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend allows implicit fall-off on :never (runtime TypeError); explicit `return;` is compile fatal (#4206).
     * php-cfg synthetic trailing returns have no source attributes; user `return;` carries startLine.
     */
    protected function neverFunctionReturnIsImplicitFalloff(Op\Terminal\Return_ $return): bool
    {
        $attrs = $return->getAttributes();

        return [] === $attrs || !isset($attrs['startLine']);
    }

    /**
     * Arrow `fn(): never => expr` is not Zend's explicit-`return` compile fatal — expression bodies
     * TypeError at call time with "must not implicitly return" (zend_compile.c / #30020).
     */
    protected function neverFunctionIsArrowExpressionBody(Block $block): bool
    {
        $func = $block->func;
        if (null === $func) {
            return false;
        }

        return ($func->callableOp ?? null) instanceof Op\Expr\ArrowFunction;
    }

    /**
     * @param list<Operand\BoundVariable> $closureUseVars
     */
    protected function registerClosureUseCapturesOnBlock(Block $funcBlock, array $closureUseVars): void
    {
        foreach ($closureUseVars as $useVar) {
            $name = $this->boundVariableName($useVar);
            $slot = $funcBlock->getVarSlot($useVar, false);
            $funcBlock->closureCaptureSlots[$slot] = true;
            $funcBlock->closureCaptureSlotNames[$slot] = $name;
            if ($useVar->byRef) {
                $funcBlock->closureCaptureByRef[$slot] = true;
            }
        }
    }

    /**
     * @param list<Operand\BoundVariable> $closureUseVars
     */
    protected function compileCfgBlock(
        CfgBlock $block,
        array $params = [],
        ?CfgFunc $func = null,
        array $closureUseVars = []
    ): Block {
        if (null === $this->seen) {
            $this->seen = new SplObjectStorage;
        }
        if (!$this->seen->contains($block)) {
            $savedDeferredArrayLiteralKeepSlots = $this->deferredArrayLiteralKeepSlots;
            $this->deferredArrayLiteralKeepSlots = [];
            $this->seen[$block] = $new = new Block($block);
            if ($this->compilingArrowAutoCapture) {
                $new->arrowAutoCapture = true;
            }
            if (null !== $func) {
                $new->func = $func;
                $new->strictTypes = isset($func->strictTypes) ? (bool) $func->strictTypes : false;
                $this->applyReturnTypeFromFunc($new, $func);
                $new->functionNamedCvSlots = new \ArrayObject();
            }
            if ([] !== $params) {
                $this->assertNoDuplicateParameterNames($params);
                $this->assertNoThisAsParameter($params);
                $this->assertNoDuplicateParameterAttributes($params, $func);
                $this->assertReadonlyParamOnlyInConstructor($params, $func);
                $this->assertVariadicParamIsLast($params);
            }
            $paramIdx = 0;
            foreach ($params as $param) {
                $new->addOpCode($this->compileParam($param, $new, $paramIdx++));
            }
            $this->maybeEmitOptionalBeforeRequiredParamDeprecations($params, $new);
            if (null !== $func && '__construct' === $func->name && null !== $func->class) {
                $this->compileCtorPromotionAssignments($new, $params);
            }
            if ([] !== $closureUseVars) {
                $this->registerClosureUseCapturesOnBlock($new, $closureUseVars);
            }
            // Zend early-binds top-level function decls in {main} for the whole compile unit
            // (not nested in if/try/switch/loop). php-cfg places those Stmt_Function in later
            // merge blocks after try/if, so per-block hoist alone misses call sites inside the
            // control-flow body that appear textually before the declaration (#24807).
            if (
                null !== $func
                && '{main}' === $func->name
                && null === $func->class
                && $block === $func->cfg
            ) {
                // Attribute args on early-bound FUNCDEFs fold userland consts; those consts are
                // otherwise prescanned only inside compileOps, which runs after this hoist (#26628).
                $this->prescanCompileTimeGlobalConsts($block->children, $new);
                $this->emitEarlyBoundFunctionDefs($block, $new);
            }
            $this->compileBlock($new);
            foreach ($this->deferredArrayLiteralKeepSlots as $slot => $_) {
                $new->deferredArrayLiteralKeepSlots[$slot] = true;
            }
            $this->deferredArrayLiteralKeepSlots = $savedDeferredArrayLiteralKeepSlots;
        }
        /** @var mixed $out */
        $out = $this->seen[$block] ?? null;
        if (!$out instanceof Block) {
            if (null === $this->compileAbortDetail) {
                $this->compileAbortDetail = 'Compiler::compileCfgBlock: seen map returned non-Block';
            }
            // Best effort: keep going with a fresh Block so callers can surface a meaningful abort later.
            $out = new Block($block);
            $this->seen[$block] = $out;
        }

        return $out;
    }

    /**
     * CFG branch target within the current function: inherit parent locals ($this, params).
     */
    protected function compileCfgBranch(CfgBlock $block, Block $parent): Block {
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            $new->inheritScopeFrom($parent);
            if (!$this->compilingSwitchJumpIfChain) {
                $this->inheritCfgVarSlotsFromSiblingCfgBranches($block, $new);
                $this->applyTernaryMergeVarSlots($block, $new);
            }
            if ($this->isErrorSuppressEndBlock($block)) {
                $this->inheritErrorSuppressExpressionSlots($parent, $new);
            }
            $this->inheritFuncFromParent($new, $parent);
            // Match/ternary branch blocks reuse unnamed temporaries (subject slot) from the parent (#4274).
            $new->inheritUndefinedLocals = true;
            if ($block instanceof ErrorSuppressBlock) {
                $new->addOpCode(new OpCode(OpCode::TYPE_BEGIN_SILENCE));
            }
            $this->compileBlock($new);
            if (!$this->compilingSwitchJumpIfChain) {
                $this->recordTernaryMergeVarSlots($block, $new);
            }
        } else {
            $child = $this->seen[$block];
            // Merge blocks already mapped on first branch; sibling inheritScopeFrom
            // adds duplicate slot indices and breaks ?: echo (#3790).
            // Try/catch end often has only one CFG parent (the catch), so the parents>=2
            // guard does not apply — re-inheriting from the catch aliases method-name /
            // "caught:" temps onto the merge echo slot and AFTER prints the wrong string
            // (#23930, #23641 AFTER regression). Skip once the merge already has opcodes.
            if (\count($block->parents) < 2 && 0 === $child->nOpCodes) {
                $child->inheritScopeFrom($parent);
                if ($this->isErrorSuppressEndBlock($block)) {
                    $this->inheritErrorSuppressExpressionSlots($parent, $child);
                }
            }
            $this->inheritFuncFromParent($child, $parent);
        }
        $child = $this->seen[$block];
        $child->parents[] = $parent;

        return $child;
    }

    /** Switch/if/loop targets need enclosing Func for JIT visibility (#210, #588). */
    private function inheritFuncFromParent(Block $child, Block $parent): void
    {
        if (null !== $parent->func) {
            $child->func = $parent->func;
            $child->strictTypes = $parent->strictTypes;
            // Merge blocks skip inheritScopeFrom when parents>=2 (#3790); still need
            // return-type flags so :never epilogue checks run on implicit fall-off (#9240).
            $child->returnTypeConstraint = $parent->returnTypeConstraint;
            $child->returnClassConstraint = $parent->returnClassConstraint;
            $child->returnDeclaredTypeLabel = $parent->returnDeclaredTypeLabel;
            $child->returnDnfConstraints = $parent->returnDnfConstraints;
            $child->returnTypeVoid = $parent->returnTypeVoid;
            $child->returnTypeNever = $parent->returnTypeNever;
            $child->returnTypeStatic = $parent->returnTypeStatic;
            $child->returnTypeMixed = $parent->returnTypeMixed;
            $child->returnDeclaredType = $parent->returnDeclaredType;
            $child->returnLiteralBoolType = $parent->returnLiteralBoolType;
        }
        // Share function-wide CV map across CFG arms (Parsedown `$text` if/elseif, #36380).
        if (null !== $parent->functionNamedCvSlots) {
            $child->functionNamedCvSlots = $parent->functionNamedCvSlots;
        } elseif (null !== $child->functionNamedCvSlots) {
            $parent->functionNamedCvSlots = $child->functionNamedCvSlots;
        } else {
            $shared = new \ArrayObject();
            $child->functionNamedCvSlots = $shared;
            $parent->functionNamedCvSlots = $shared;
        }
    }

    /**
     * ?: / if branches must assign the merge temporary in one scope slot (#3790, #137).
     */
    private function inheritCfgVarSlotsFromSiblingCfgBranches(CfgBlock $cfgBlock, Block $compiled): void
    {
        foreach ($cfgBlock->children as $child) {
            if (!$child instanceof Op\Stmt\Jump) {
                continue;
            }
            $merge = $child->target;
            if (\count($merge->parents) < 2) {
                continue;
            }
            foreach ($merge->parents as $siblingCfg) {
                if ($siblingCfg === $cfgBlock || !$this->seen->contains($siblingCfg)) {
                    continue;
                }
                $sibling = $this->seen[$siblingCfg];
                $compiled->inheritCfgVarSlotsFrom($sibling);
                // Same-name CVs (`$text` in if/elseif) must share one slot (#36380).
                $compiled->inheritNamedAssignDestsFrom($sibling);
            }
        }
    }

    /**
     * Scope slot for the local alias created by `global $name` (#3413).
     *
     * php-cfg may pass writeVariable(Literal('x')) rather than a Variable operand.
     */
    protected function compileGlobalImportSlot(Operand $var, string $globalName, Block $block): int
    {
        if ($var instanceof Operand\Variable) {
            return $block->getVarSlot($var, false);
        }
        $nameLiteral = new Operand\Literal($globalName);
        $nameLiteral->type = Type::string();
        $local = new Operand\Variable($nameLiteral);

        return $block->getVarSlot($local, false);
    }

    protected function resolveSimpleVariableName(Operand $var): string
    {
        while ($var instanceof Temporary) {
            if (null === $var->original) {
                break;
            }
            $var = $var->original;
        }
        if ($var instanceof Operand\Literal && is_string($var->value)) {
            return $var->value;
        }
        if (!$var instanceof Operand\Variable) {
            $this->throwCompileLogic('Expected a simple variable operand');
        }
        $name = $var->name;
        while ($name instanceof Temporary) {
            if (null === $name->original) {
                break;
            }
            $name = $name->original;
        }
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            return $name->value;
        }

        $this->throwCompileLogic('Expected a simple variable name');
    }

}
