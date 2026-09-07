<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\ClassConstName;
use PHPCompiler\OpCode;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Variable as CfgVariable;

/**
 * Invokable-receiver, closure-value, and `(new C)(...)` __invoke helpers (#36387 / #36403).
 *
 * Extracted from {@see FirstClassCallableAndClosure} so gen-0 split-TU can hollow
 * a smaller Concern TU. FCC / Closure::fromCallable compile stays in the parent;
 * this trait owns `$v()` → `__invoke` gating, assigned-closure detection, and
 * parenthesized-new call skipping (php-src Zend/zend_compile.c / zend_closures.c).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FirstClassCallableAndClosure).
 */
trait InvokableReceiverAndClosureDetect
{
    protected function operandIsInvokableReceiver(Operand $operand, Block $block): bool
    {
        // First-class callables are Closure objects; use FUNC_CALL dispatch, not `$x->__invoke(...)`.
        if (null !== $block->orig) {
            $root = $this->unwrapOperandChain($operand);
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\Assign) {
                    continue;
                }
                if (!$this->operandsReferToSameVariable($child->var, $root)) {
                    continue;
                }
                if ($child->expr instanceof Op\Expr\FirstClassCallable) {
                    return false;
                }
            }
        }

        if ($this->operandHasObjectType($operand)
            && !$this->variableAssignIsNullableClosureBinding($operand, $block)
            && $this->operandObjectTypeHasProvableInvoke($operand, $block)) {
            return true;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ClassConstFetch
            && $this->classConstFetchIsInvokableEnumCase($root, $block)) {
            return true;
        }
        if ($root instanceof Op\Expr\New_) {
            return $this->newExprHasInvokeMethod($root, $block);
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($this->assignExprIsNullableClosureBinding($child->expr)) {
                continue;
            }
            if ($this->operandDerivesFromNew($child->expr, $block)) {
                $new = $this->findNewExprForCalleeOperand($operand, $block);
                if (null !== $new && $this->newExprHasInvokeMethod($new, $block)) {
                    return true;
                }
                continue;
            }
            if ($this->operandDerivesFromClosure($child->expr)) {
                return true;
            }
            if ($this->operandHasObjectType($child->expr)
                && $this->operandObjectTypeHasProvableInvoke($child->expr, $block)) {
                return true;
            }
            if ($child->expr instanceof Op\Expr\ClassConstFetch
                && $this->classConstFetchIsInvokableEnumCase($child->expr, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only rewrite `$v()` to `$v->__invoke()` when __invoke is provable at compile time (#17745).
     *
     * Untyped or non-invokable objects keep FUNCCALL_INIT so Zend callable errors apply.
     */
    protected function operandObjectTypeHasProvableInvoke(Operand $operand, Block $block): bool
    {
        if ($this->callArgOperandIsAssignedClosure($operand, $block)) {
            return true;
        }
        $new = $this->findNewExprForCalleeOperand($operand, $block);
        if (null !== $new) {
            return $this->newExprHasInvokeMethod($new, $block);
        }
        $className = $this->unwrapOperandChain($operand)->type?->userType;
        if (null === $className || '' === ltrim($className, '\\')) {
            return false;
        }
        $lcClass = strtolower(ltrim($className, '\\'));
        if ('closure' === $lcClass) {
            return true;
        }

        return $this->declaredClassHasInstanceMethod($lcClass, '__invoke', $block);
    }

    /**
     * @param non-empty-string $lcClass
     */
    protected function declaredClassHasInstanceMethod(string $lcClass, string $methodLc, Block $block): bool
    {
        $methodLc = strtolower($methodLc);
        // Prefer ClassCompileRegistry — class stmts are hoisted into other CFG blocks (#26426).
        if ($this->classCompileRegistry->hasMethod($lcClass, $methodLc)) {
            return true;
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $name = $this->literalScopeClassName($child->name);
            if (null === $name || strtolower($name) !== $lcClass) {
                continue;
            }
            foreach ($child->stmts->children as $stmt) {
                if ($stmt instanceof Op\Stmt\ClassMethod && strtolower($stmt->func->name) === $methodLc) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function variableAssignIsNullableClosureBinding(Operand $operand, Block $block): bool
    {
        if ($this->variableAssignIsNullableClosureBindingInOrig($operand, $block)) {
            return true;
        }
        $root = $this->unwrapOperandChain($operand);
        if (!$root instanceof CfgVariable) {
            return false;
        }
        $slot = null;
        foreach ($block->eachCfgVarRootSlot() as [$varRoot, $varSlot]) {
            if ($varRoot === $root) {
                $slot = $varSlot;
                break;
            }
        }
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot) {
                continue;
            }
            $rhs = $block->getOperand((int) $op->arg3);
            if ($this->assignExprIsNullableClosureBinding($rhs)) {
                return true;
            }
        }

        return false;
    }

    private function variableAssignIsNullableClosureBindingInOrig(Operand $operand, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($this->assignExprIsNullableClosureBinding($child->expr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parenthesized enum case `(E::A)()` is a callable object, not a string callee (#7386).
     */
    private function classConstFetchIsInvokableEnumCase(
        Op\Expr\ClassConstFetch $fetch,
        Block $block
    ): bool {
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            $lcClass = strtolower(ltrim($className, '\\'));
        }
        $lcConst = ClassConstName::key($constName);
        if (isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return true;
        }
        if (!isset($this->compileTimeClassConsts[$lcClass][$lcConst])) {
            return false;
        }
        $stored = $this->compileTimeClassConsts[$lcClass][$lcConst];

        return Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject()));
    }

    protected function operandDerivesFromClosure(Operand $operand): bool
    {
        $root = $this->unwrapOperandChain($operand);

        return $root instanceof Op\Expr\Closure || $root instanceof Op\Expr\ArrowFunction;
    }

    /** php-cfg assigns closure callbacks to temps before user-comparator calls (#8947, array_udiff). */
    private function callArgOperandIsAssignedClosure(Operand $operand, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }

            return $this->exprDerivesFromClosure($child->expr);
        }

        return false;
    }

    /** Assign RHS is the same inline closure CFG node or a temp referring to it (#5644, composer autoload). */
    private function assignExprMatchesClosureProducer(Operand|Op\Expr $assignExpr, Op\Expr $producer): bool
    {
        if ($assignExpr === $producer) {
            return true;
        }
        if (!$assignExpr instanceof Operand) {
            return false;
        }
        if (null !== $producer->result) {
            return $this->operandsReferToSameVariable($assignExpr, $producer->result);
        }

        return false;
    }

    private function exprDerivesFromClosure(Operand|Op\Expr $expr): bool
    {
        if ($expr instanceof Op\Expr\Closure || $expr instanceof Op\Expr\ArrowFunction) {
            return true;
        }
        if ($expr instanceof Operand) {
            return $this->operandDerivesFromClosure($expr);
        }

        return false;
    }

    /** Inline or assigned closure comparators must not consume hoisted enum prelude slots (#8947). */
    private function callArgOperandIsClosureValue(Operand $operand, Block $block, ?string $calleeName = null): bool
    {
        if ($this->callArgIsNullLiteral($operand)) {
            return false;
        }
        if ($this->isEmbeddedCallLiteralArg($operand)) {
            return false;
        }
        if ($this->operandDerivesFromClosure($operand)) {
            return true;
        }
        if ($this->unwrapOperandChain($operand) instanceof Op\Expr\FirstClassCallable) {
            return true;
        }
        if ($this->callArgOperandIsAssignedClosure($operand, $block)) {
            return true;
        }
        if (null === $block->orig) {
            return false;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $operand);
        if (null !== $callSite) {
            [$callOp, $argIndex] = $callSite;
            if (
                0 === $argIndex
                && $this->cfgCallAcceptsSingleInlineClosureCallback($callOp)
            ) {
                foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp) as $candidate) {
                    if ($candidate instanceof Op\Expr\Closure || $candidate instanceof Op\Expr\ArrowFunction) {
                        return true;
                    }
                }
            }
            if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                foreach ($producers as $candidate) {
                    if ($candidate instanceof Op\Expr\FirstClassCallable
                        && null !== $this->matchSingleFirstClassCallableInlineProducer(
                            $candidate,
                            $callOp->args,
                            $argIndex,
                            $this->resolveInlineCallArgFuncName($callOp, $calleeName)
                        )) {
                        return true;
                    }
                    if (
                        ($candidate instanceof Op\Expr\ArrowFunction || $candidate instanceof Op\Expr\Closure)
                        && null !== $this->matchSingleClosureInlineProducer(
                            $candidate,
                            $callOp->args,
                            $argIndex,
                            $this->resolveInlineCallArgFuncName($callOp, $calleeName)
                        )
                    ) {
                        return true;
                    }
                }
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block, $calleeName);
                if ($producer instanceof Op\Expr\ArrowFunction
                    || $producer instanceof Op\Expr\Closure
                    || $producer instanceof Op\Expr\FirstClassCallable) {
                    return true;
                }
            }
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable) {
                if ($this->operandsReferToSameVariable($child->result, $operand)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * bind/bindTo may return null at runtime (internal scope, missing class); do not
     * compile $v() as $v->__invoke() from assign-chain inference (#5170, zend_closures.c).
     */
    private function assignExprIsNullableClosureBinding(?Operand $operand): bool
    {
        if (null === $operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\MethodCall) {
            $method = $this->staticNameFromOperand($root->name);

            return null !== $method && in_array(strtolower($method), ['bind', 'bindto'], true);
        }
        if ($root instanceof Op\Expr\StaticCall) {
            $class = $this->staticNameFromOperand($root->class);
            $method = $this->staticNameFromOperand($root->name);

            return null !== $class
                && null !== $method
                && 'closure' === strtolower(ltrim($class, '\\'))
                && 'bind' === strtolower($method);
        }

        return false;
    }

    protected function operandDerivesFromNew(?Operand $operand, Block $block): bool
    {
        return null !== $this->findNewExprForCalleeOperand($operand, $block);
    }

    /**
     * Zend: `(new C)(...)` applies outer args only when `__invoke` exists (#10176, zend_compile.c).
     */
    protected function parensNewCallSkippedWithoutInvoke(Operand $callee, Block $block): bool
    {
        $new = $this->findNewExprForCalleeOperand($callee, $block);
        if (null === $new) {
            return false;
        }

        return !$this->newExprHasInvokeMethod($new, $block);
    }

    protected function findNewExprForCalleeOperand(?Operand $operand, Block $block): ?Op\Expr\New_
    {
        if (null === $operand || null === $block->orig) {
            return null;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\New_ && $this->unwrapOperandChain($child->result) === $root) {
                return $child;
            }
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($child->expr instanceof Op\Expr\New_) {
                return $child->expr;
            }
        }

        return null;
    }

    protected function newExprHasInvokeMethod(Op\Expr\New_ $new, Block $block): bool
    {
        $className = $this->literalScopeClassName($new->class);
        // Named classes: registry sees decls hoisted out of try/catch CFG blocks (#26426).
        if (null !== $className && '' !== $className
            && $this->classCompileRegistry->hasMethod($className, '__invoke')) {
            return true;
        }
        if (null === $className || null === $block->orig) {
            return false;
        }
        // Same-block fallback (anonymous `new class { function __invoke… }` / #10176).
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            if ($className !== $this->literalScopeClassName($child->name)) {
                continue;
            }
            foreach ($child->stmts->children as $stmt) {
                if (!$stmt instanceof Op\Stmt\ClassMethod) {
                    continue;
                }
                if ('__invoke' === strtolower($stmt->func->name)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
