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
 * First-class callables, Closure::fromCallable, and invokable/new→__invoke helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see compileFirstClassCallable}, Closure::fromCallable lowering,
 * invokable-receiver detection, and `(new C)(...)` __invoke gating
 * (php-src Zend/zend_compile.c / zend_closures.c).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; FCC
 * slot wiring relies on coercion (same as EchoCoalesceCallArgCompile).
 */
trait FirstClassCallableAndClosure
{
    /**
     * Lower Closure::fromCallable(constant|[$obj,'m']) to TYPE_FROM_CALLABLE — same as FCC (#26788).
     *
     * Marks {@see OpCode::$fromCallableApi} so VM/JIT use Closure::fromCallable semantics
     * (bind `$this` for `[Class, instanceMethod]`, TypeError prefix) rather than FCC (#27138).
     *
     * Object-array form `[$this, 'method']` must not fold `$this` to class name `"this"`
     * (#27137, #27143, #23688) — lower like bound-method FCC instead.
     *
     * @return OpCode[]|null
     */
    private function tryCompileClosureFromCallableAsFcc(Op\Expr\StaticCall $expr, Block $block): ?array
    {
        $className = $this->literalScopeClassName($expr->class)
            ?? $this->staticNameFromOperand($expr->class);
        $methodName = $this->staticNameFromOperand($expr->name);
        if (null === $className || null === $methodName) {
            return null;
        }
        if ('closure' !== strtolower(ltrim($className, '\\'))) {
            return null;
        }
        if ('fromcallable' !== strtolower($methodName)) {
            return null;
        }
        if (1 !== \count($expr->args)) {
            return null;
        }
        $boundArray = $this->tryCompileClosureFromCallableObjectArray($expr, $block);
        if (null !== $boundArray) {
            return $boundArray;
        }
        $callableName = $this->literalCallableNameForFromCallable($expr->args[0], $block, $expr);
        if (null === $callableName) {
            return null;
        }
        $result = $this->compileOperand($expr->result, $block, false);
        $callableSlot = $this->compileOperand(new Operand\Literal($callableName), $block, true);
        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        );
        $fromCallable->fromCallableApi = true;
        $this->assignSourceMetadata($fromCallable, $expr);
        return [$fromCallable];
    }

    /**
     * Closure::fromCallable([$obj, 'method']) → INIT_ARRAY + TYPE_FROM_CALLABLE (#27137, #27143).
     *
     * Same shape as {@see compileBoundMethodFirstClassCallable}; keeps the object receiver
     * instead of folding Variable(`this`) to the string class name `"this"`.
     * Runtime method names (Slim `[$this->creator, $this->method]`) stay as operands (#36382).
     *
     * @return OpCode[]|null
     */
    private function tryCompileClosureFromCallableObjectArray(Op\Expr\StaticCall $expr, Block $block): ?array
    {
        $arrayExpr = $this->findFromCallableArrayExpr($expr->args[0], $block, $expr);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        $values = $arrayExpr->values ?? [];
        if (2 !== \count($values)) {
            return null;
        }
        // Class-name string / Class::class → string callable path (#27138).
        if (null !== $this->literalCallableArrayElementString($values[0], $block)) {
            return null;
        }
        $methodLiteral = $this->literalCallableArrayElementString($values[1], $block)
            ?? $this->literalStringAssignedToOperand($values[1], $block);
        $result = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($values[0], $block, true);
        $methodSlot = null !== $methodLiteral
            ? $this->compileOperand(new Operand\Literal($methodLiteral), $block, true)
            : $this->compileOperand($values[1], $block, true);
        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $result
        );
        $fromCallable->fromCallableApi = true;
        $this->assignSourceMetadata($fromCallable, $expr);
        return [
            new OpCode(
                OpCode::TYPE_INIT_ARRAY,
                $result,
                $receiverSlot,
                $this->compileIntegerLiteralSlot(0, $block)
            ),
            new OpCode(
                OpCode::TYPE_ADD_ARRAY_ELEMENT,
                $result,
                $methodSlot,
                $this->compileIntegerLiteralSlot(1, $block)
            ),
            $fromCallable,
        ];
    }

    private function findFromCallableArrayExpr(Operand $arg, Block $block, Op\Expr\StaticCall $callOp): ?Op\Expr\Array_
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\Array_
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $arg)
            ) {
                return $child;
            }
            // `$c = [$obj, $m]; Closure::fromCallable($c)` — Slim ServerRequestCreator (#36382).
            if (
                $child instanceof Op\Expr\Assign
                && null !== $child->var
                && $this->operandsReferToSameVariable($child->var, $arg)
                && ($child->expr ?? null) instanceof Operand
            ) {
                foreach ($block->orig->children as $inner) {
                    if (
                        $inner instanceof Op\Expr\Array_
                        && null !== $inner->result
                        && $this->operandsReferToSameVariable($inner->result, $child->expr)
                    ) {
                        return $inner;
                    }
                }
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (\is_int($callIndex) && $callIndex > 0) {
            $prev = $block->orig->children[$callIndex - 1] ?? null;
            if ($prev instanceof Op\Expr\Array_) {
                return $prev;
            }
        }
        return null;
    }

    /** Resolve Temporary holding a string literal Assign (php-cfg array element shape). */
    private function literalStringAssignedToOperand(Operand $op, Block $block): ?string
    {
        if ($op instanceof Operand\Literal && \is_string($op->value)) {
            return $op->value;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\Assign
                && null !== $child->var
                && $this->operandsReferToSameVariable($child->var, $op)
            ) {
                $expr = $child->expr ?? null;
                if ($expr instanceof Operand\Literal && \is_string($expr->value)) {
                    return $expr->value;
                }
            }
        }
        return null;
    }

    private function literalCallableNameForFromCallable(Operand $arg, Block $block, Op\Expr\StaticCall $callOp): ?string
    {
        $direct = $this->staticNameFromOperand($arg);
        if (null !== $direct) {
            return $direct;
        }
        $arrayExpr = $this->findFromCallableArrayExpr($arg, $block, $callOp);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        $values = $arrayExpr->values ?? [];
        if (2 !== \count($values)) {
            return null;
        }
        $classPart = $this->literalCallableArrayElementString($values[0], $block);
        $methodPart = $this->literalCallableArrayElementString($values[1], $block)
            ?? $this->literalStringAssignedToOperand($values[1], $block);
        if (null === $classPart || null === $methodPart) {
            return null;
        }
        return $classPart.'::'.$methodPart;
    }

    private function literalCallableArrayElementString(Operand $op, Block $block): ?string
    {
        // Only true string literals — Variable(name) may be `$this` / `$obj` and must not
        // fold to a class-name string for Closure::fromCallable (#27137, #27138, #23688).
        if ($op instanceof Operand\Literal && \is_string($op->value)) {
            return $op->value;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\ClassConstFetch
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $op)
            ) {
                $name = $this->staticNameFromOperand($child->name);
                if ('class' === strtolower((string) $name)) {
                    return $this->literalScopeClassName($child->class)
                        ?? $this->staticNameFromOperand($child->class);
                }
            }
        }
        return null;
    }

    /**
     * Lower PHP 8.1 first-class callables to Closure objects via TYPE_FROM_CALLABLE (#1230, #4810).
     *
     * @return OpCode[]
     */
    protected function compileFirstClassCallable(Op\Expr\FirstClassCallable $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        // `parent::` / `self::` / `static::` instanceMethod(...) — bound `$this` closures (#17655, #26630).
        // Static methods / static context: fall through to `"Class::m"` string FCC (#26252).
        // Binding `$this` from a static method yields a null receiver and fromCallable fails.
        if (Op\Expr\FirstClassCallable::KIND_STATIC === $expr->kind && null !== $expr->class) {
            $this->rejectPseudoClassFetchOutsideKnownClassScope(
                $this->firstClassCallableScopeKeyword($expr->class),
                $block,
                $expr
            );
        }
        if (
            Op\Expr\FirstClassCallable::KIND_STATIC === $expr->kind
            && null !== $expr->class
            && !$this->blockIsStaticMethodContext($block)
        ) {
            $scope = $this->firstClassCallableScopeKeyword($expr->class);
            if (null !== $scope) {
                return $this->compileBoundMethodFirstClassCallable(
                    $expr,
                    $block,
                    $result,
                    new Operand\Variable(new Operand\Literal('this')),
                    // `static::` is late-bound (virtual); `self`/`parent` pin the resolve class.
                    'static' === $scope ? null : $scope
                );
            }
        }
        // Numeric kinds: avoid php-cfg class const fetch during self-host bundle JIT (#1056).
        if (3 === $expr->kind) {
            return $this->compileBoundMethodFirstClassCallable(
                $expr,
                $block,
                $result,
                $expr->var,
                null
            );
        }

        // php-src never accepts `new Class(...)` FCC (Zend/zend_compile.c; #10130, #26188).
        if (Op\Expr\FirstClassCallable::KIND_NEW === $expr->kind) {
            $this->throwCompileError('Cannot create Closure for new expression');
        }

        $scopeKeyword = null;
        if (2 === $expr->kind && null !== $expr->class) {
            $scopeKeyword = $this->firstClassCallableScopeKeyword($expr->class);
        }

        if (1 === $expr->kind) {
            if ($expr->name instanceof Operand\Literal) {
                $callableSlot = $this->compileFirstClassFunctionNameSlot($expr->name, $block);
            } else {
                // Enum case `(E::A)(...)` is KIND_FUNCTION with non-literal name (#6851, zend_compile.c).
                $callableSlot = $this->compileOperand($expr->name, $block, true);
            }
        } elseif (2 === $expr->kind) {
            $callableSlot = $this->compileFirstClassStaticNameSlot($expr->class, $expr->name, $block);
        } else {
            $this->throwCompileLogic('Unknown first-class callable kind');
        }

        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        );
        // Bake self/parent → fqcn for AOT/JIT lookup, but keep the keyword so VM can preserve
        // creation-time late-static called_scope (B::viaSelf with self::foo → B, not A) (#27835).
        if ('self' === $scopeKeyword || 'parent' === $scopeKeyword) {
            $fromCallable->fromCallableScope = $scopeKeyword;
        }
        // FCC Error throw site needs opcode line for getLine() (#24397, zend_exceptions.c).
        $this->assignSourceMetadata($fromCallable, $expr);

        return [$fromCallable];
    }

    /**
     * Lower `$obj->m(...)` / `parent|self|static::m(...)` to `[receiver, method]` + TYPE_FROM_CALLABLE
     * (#3566, #17655, #26630).
     *
     * @param null|'parent'|'self' $scope  null = virtual (`$obj->` / `static::`); pin for self/parent
     *
     * @return OpCode[]
     */
    private function compileBoundMethodFirstClassCallable(
        Op\Expr\FirstClassCallable $expr,
        Block $block,
        int $result,
        Operand $receiver,
        ?string $scope = null
    ): array {
        $callableSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($receiver, $block, true);
        $methodSlot = $this->compileOperand($expr->name, $block, true);
        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        );
        $fromCallable->fromCallableScope = $scope;
        $this->assignSourceMetadata($fromCallable, $expr);

        return [
            new OpCode(
                OpCode::TYPE_INIT_ARRAY,
                $callableSlot,
                $receiverSlot,
                $this->compileIntegerLiteralSlot(0, $block)
            ),
            new OpCode(
                OpCode::TYPE_ADD_ARRAY_ELEMENT,
                $callableSlot,
                $methodSlot,
                $this->compileIntegerLiteralSlot(1, $block)
            ),
            $fromCallable,
        ];
    }

    private function compileFirstClassFunctionNameSlot(Operand $name, Block $block): int
    {
        if (!$name instanceof Operand\Literal) {
            $this->throwCompileLogic('First-class function callable name must be a literal');
        }

        return $this->compileStringLiteralSlot($name->value, $block);
    }

    private function compileFirstClassStaticNameSlot(?Operand $class, Operand $method, Block $block): int
    {
        if (!$class instanceof Operand\Literal || !$method instanceof Operand\Literal) {
            $this->throwCompileLogic('First-class static callable requires literal class and method names');
        }
        $className = $this->resolveFirstClassStaticClassName((string) $class->value, $block);

        return $this->compileStringLiteralSlot($className.'::'.$method->value, $block);
    }

    /**
     * Resolve `parent` / `self` in FCC Class::method strings for AOT/JIT (#26252).
     *
     * VM {@see ClosureSupport::resolveClassScopeName} rewrites at runtime; native emit must
     * bake a real class name or lookup fails with `undefined static method parent::m()`.
     */
    private function resolveFirstClassStaticClassName(string $className, Block $block): string
    {
        $lc = strtolower($className);
        if ('parent' === $lc) {
            if (null !== $this->compilingClassParentLc && '' !== $this->compilingClassParentLc) {
                return $this->compilingClassParentDisplayName() ?? $this->compilingClassParentLc;
            }
            $this->throwCompileError('Cannot use "parent" when current class scope has no parent');
        }
        if ('self' === $lc) {
            if (null !== $block->func && null !== $block->func->class && '' !== (string) $block->func->class->value) {
                return (string) $block->func->class->value;
            }
            if (null !== $this->compilingClassLc && '' !== $this->compilingClassLc) {
                return $this->compilingClassLc;
            }
        }

        return $className;
    }

    /** Display name for the class currently being compiled's extends clause (#26252). */
    private function compilingClassParentDisplayName(): ?string
    {
        if (null !== $this->compilingClassParentName && '' !== $this->compilingClassParentName) {
            return $this->compilingClassParentName;
        }
        if (null === $this->compilingClassParentLc || '' === $this->compilingClassParentLc) {
            return null;
        }

        return $this->compilingClassParentLc;
    }

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
    }}
