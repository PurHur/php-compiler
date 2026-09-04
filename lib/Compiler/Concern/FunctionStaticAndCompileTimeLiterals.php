<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;

/**
 * Function-local static compile + shared CFG literal / array CT helpers (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see functionStaticStorageKey}, {@see resolveFuncDisplayName},
 * {@see compileFunctionStaticVar}, static-init guards / default fold,
 * {@see tryBuildCompileTimeArrayFromExpr}, {@see vmVariableFromCfgLiteralOperand},
 * and {@see unwrapCfgLiteralOperand}.
 *
 * php-src: Zend/zend_compile.c (`zend_compile_static_var` / `zend_compile_static_variables`).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; CT fold and
 * static-default wiring rely on coercion (same as CompileTimeFold / FinalizeArrayFamily).
 */
trait FunctionStaticAndCompileTimeLiterals
{
    protected function functionStaticStorageKey(\PHPCfg\Func $func, string $varName): string
    {
        if (((int) ($func->flags ?? 0)) & \PHPCfg\Func::FLAG_CLOSURE) {
            return $varName;
        }

        return $this->resolveFuncDisplayName($func)."\0".$varName;
    }

    protected function resolveFuncDisplayName(\PHPCfg\Func $func): string
    {
        $name = $func->name;
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            $name = $name->value;
        }
        if (!is_string($name)) {
            $this->throwCompileLogic('Function name must be a string literal for static storage key (#2286)');
        }
        $class = $func->class;
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            $class = $class->value;
        }
        if (null !== $class && !is_string($class)) {
            $this->throwCompileLogic('Function class must be a string literal for static storage key (#2286)');
        }

        return null !== $class ? $class.'::'.$name : $name;
    }

    /**
     * @param Op\Terminal\StaticVar $terminal
     *
     * @return array{0: list<OpCode>, 1: Block}
     */
    protected function compileFunctionStaticVar(Op\Terminal $terminal, Block $block): array
    {
        if (null === $block->func) {
            $this->throwCompileLogic('Function-local static requires a function context');
        }
        $varName = $this->resolveSimpleVariableName($terminal->var);
        $this->assertNoThisAsStaticVariable($varName, $terminal);
        $storageKey = $this->functionStaticStorageKey($block->func, $varName);
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($storageKey);
        $keyOperand = new Operand\Literal($storageKey);
        $keyOperand->type = Type::string();
        $keySlot = $block->registerConstant($keyOperand, $keyVar);
        $localSlot = $this->compileOperand($terminal->var, $block, false);
        $declaredType = $this->staticVarDeclaredType($terminal);
        $typeSlot = null;
        if (null !== $declaredType) {
            $declType = $this->typeFromStaticVarDecl($terminal, $declaredType);
            $typeSlot = $this->compileTypeConstrainedVariable($block, $declType, $declaredType);
        }

        if (null === $terminal->defaultVar) {
            return [[$this->makeDeclareFunctionStaticOp(
                $localSlot,
                $keySlot,
                null,
                $typeSlot,
                $varName
            )], $block];
        }

        $defaultSlot = $this->tryFoldFunctionStaticDefaultSlot($terminal, $block);
        if (null !== $defaultSlot) {
            $defaultVm = $block->constants[$defaultSlot];
            if (!$this->isAllowedFunctionStaticDefaultType($defaultVm->type)) {
                $this->throwCompileLogic(
                    'Function-local static initializer must be a compile-time literal in v1 (#2286)'
                );
            }
            if (null !== $declaredType) {
                $this->assertCompileTimeDefaultMatchesDeclaredType(
                    $defaultVm,
                    $declaredType,
                    'static variable',
                    '$'.$varName,
                    $block,
                    $defaultSlot
                );
            }

            return [[$this->makeDeclareFunctionStaticOp(
                $localSlot,
                $keySlot,
                $defaultSlot,
                $typeSlot,
                $varName
            )], $block];
        }

        $this->assertFunctionStaticRuntimeInitAllowed($terminal);

        $continueBlock = new Block($block->orig);
        $continueBlock->func = $block->func;
        $continueBlock->inheritScopeFrom($block);

        // JUMPIF must precede New_/Array_ rematerialization. Compiling the defaultBlock first
        // left TYPE_NEW ahead of the initialized check, so every call allocated a discarded object
        // (#28040 companion — wastes work; frame-teardown refcount fix is in VM.php).
        $skipOp = new OpCode(
            OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED,
            null,
            $keySlot
        );
        $skipOp->block1 = $continueBlock;
        $block->addOpCode($skipOp);

        if (null !== $terminal->defaultBlock) {
            // php-cfg places Array_/New_ in defaultBlock, not the function body. Rematerialize
            // so TYPE_ARG_SEND wires the INIT_ARRAY slot (not a dead mixed temp) (#22390, #8561).
            $this->compileDefaultBlockChildrenWithProducerCfg($terminal->defaultBlock, $block);
        }
        $initSlot = $this->compileOperand($terminal->defaultVar, $block, true);

        $storeOp = new OpCode(
            OpCode::TYPE_FUNCTION_STATIC_INIT_STORE,
            null,
            $keySlot,
            $initSlot
        );
        $storeOp->functionStaticTypeSlot = $typeSlot;
        $storeOp->functionStaticVarName = $varName;
        $jumpOp = new OpCode(OpCode::TYPE_JUMP);
        $jumpOp->block1 = $continueBlock;

        $continueBlock->addOpCode($this->makeDeclareFunctionStaticOp(
            $localSlot,
            $keySlot,
            null,
            $typeSlot,
            $varName
        ));
        $continueBlock->parents[] = $block;

        return [[$storeOp, $jumpOp], $continueBlock];
    }

    protected function staticVarDeclaredType(Op\Terminal\StaticVar $terminal): ?Op\Type
    {
        if (!property_exists($terminal, 'declaredType')) {
            return null;
        }

        return $terminal->declaredType;
    }

    protected function typeFromStaticVarDecl(Op\Terminal\StaticVar $terminal, ?Op\Type $declaredType = null): Type
    {
        $declaredType ??= $this->staticVarDeclaredType($terminal);
        if (null === $declaredType) {
            return Type::mixed();
        }
        if ($declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($declaredType->name);
        }

        return Type::fromTypeDecl($declaredType);
    }

    protected function makeDeclareFunctionStaticOp(
        int $localSlot,
        int $keySlot,
        ?int $defaultSlot,
        ?int $typeSlot,
        string $varName
    ): OpCode {
        $op = new OpCode(
            OpCode::TYPE_DECLARE_FUNCTION_STATIC,
            $localSlot,
            $keySlot,
            $defaultSlot
        );
        $op->functionStaticTypeSlot = $typeSlot;
        $op->functionStaticVarName = $varName;

        return $op;
    }

    /**
     * Reject non-constant function-static initializers on PHP &lt; 8.3 (#22923, #4352, #5478, #31168).
     *
     * php-cfg often places a bare `$param` on {@see Op\Terminal\StaticVar::$defaultVar} with an
     * empty {@see $defaultBlock}; walking children alone missed that shape and accepted it as a
     * runtime init (undefined-constant → string) on the 8.2 reference profile.
     *
     * First-class callables (`strlen(...)`) are not constant expressions on ≤8.2
     * ({@see Op\Expr\FirstClassCallable}); on 8.3+ they are legal arbitrary static initializers
     * (php-src `zend_compile_static_var` → `zend_compile_expr`, verified 8.3/8.4/8.5).
     *
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function assertFunctionStaticRuntimeInitAllowed(Op\Terminal $terminal): void
    {
        // PHP 8.3+ RFC: arbitrary static variable initializers (Zend/zend_compile.c).
        // FCC / closures / runtime exprs are allowed here — not gated on closures-in-const-expr
        // (that RFC is for const/attr/param/property defaults, not function-static on 8.3+).
        if (CompilerVersion::supportsArbitraryStaticVariableInitializers()) {
            return;
        }
        if (
            null !== $terminal->defaultVar
            && $this->functionStaticInitOperandReferencesLocal($terminal->defaultVar)
        ) {
            $this->throwCompileLogic(
                'Constant expression contains invalid operations'
            );
        }
        if (null === $terminal->defaultBlock) {
            return;
        }
        foreach ($terminal->defaultBlock->children as $child) {
            if ($this->functionStaticInitReferencesLocal($child)) {
                $this->throwCompileLogic(
                    'Constant expression contains invalid operations'
                );
            }
        }
    }

    protected function functionStaticInitReferencesLocal(Op $op): bool
    {
        if ($op instanceof Op\Expr\Closure || $op instanceof Op\Expr\ArrowFunction) {
            return true;
        }
        // FCC is ZEND_AST_CALLABLE_CONVERT — not a const expr on ≤8.2 (#31168 / zend_compile.c).
        if ($op instanceof Op\Expr\FirstClassCallable) {
            return true;
        }
        if ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\MethodCall) {
            return true;
        }
        if ($op instanceof Op\Expr\Variable) {
            return true;
        }
        if ($op instanceof Op\Expr\ArrayDimFetch) {
            return $this->functionStaticInitExprOrOperandReferencesLocal($op->var)
                || (null !== $op->dim && $this->functionStaticInitOperandReferencesLocal($op->dim));
        }
        if ($op instanceof Op\Expr\PropertyFetch) {
            return $this->functionStaticInitExprOrOperandReferencesLocal($op->var)
                || $this->functionStaticInitOperandReferencesLocal($op->name);
        }
        if ($op instanceof Op\Expr\BinaryOp) {
            return $this->functionStaticInitOperandReferencesLocal($op->left)
                || $this->functionStaticInitOperandReferencesLocal($op->right);
        }
        if ($op instanceof Op\Expr\UnaryMinus || $op instanceof Op\Expr\UnaryPlus || $op instanceof Op\Expr\UnaryOp\BitwiseNot) {
            return $this->functionStaticInitOperandReferencesLocal($op->expr);
        }
        if ($op instanceof Op\Expr\New_) {
            foreach ($op->args as $arg) {
                if ($this->functionStaticInitOperandReferencesLocal($arg)) {
                    return true;
                }
            }

            return false;
        }
        if ($op instanceof Op\Expr\Array_) {
            $n = \count($op->values);
            for ($i = 0; $i < $n; ++$i) {
                if ($this->functionStaticInitOperandReferencesLocal($op->values[$i])) {
                    return true;
                }
                $key = $op->keys[$i] ?? null;
                if (null !== $key && $this->functionStaticInitOperandReferencesLocal($key)) {
                    return true;
                }
            }

            return false;
        }
        if ($op instanceof Op\Expr\ConstFetch || $op instanceof Op\Expr\ClassConstFetch) {
            return false;
        }

        return false;
    }

    protected function functionStaticInitExprOrOperandReferencesLocal(Op|Operand $node): bool
    {
        if ($node instanceof Op) {
            return $this->functionStaticInitReferencesLocal($node);
        }

        return $this->functionStaticInitOperandReferencesLocal($node);
    }

    protected function functionStaticInitOperandReferencesLocal(Operand $operand): bool
    {
        if ($operand instanceof Operand\Variable) {
            return true;
        }
        if ($operand instanceof Operand\Literal || $operand instanceof Operand\NullOperand) {
            return false;
        }
        if ($operand instanceof Operand\Temporary) {
            return false;
        }

        return false;
    }

    private function isAllowedFunctionStaticDefaultType(int $type): bool
    {
        return \in_array(
            $type,
            [
                Variable::TYPE_INTEGER,
                Variable::TYPE_STRING,
                Variable::TYPE_ARRAY,
                Variable::TYPE_BOOLEAN,
                Variable::TYPE_FLOAT,
                Variable::TYPE_NULL,
                Variable::TYPE_ENUM_CASE,
                Variable::TYPE_OBJECT,
            ],
            true
        );
    }

    /**
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function tryFoldFunctionStaticDefaultSlot(Op\Terminal $terminal, Block $block): ?int
    {
        if (null === $terminal->defaultVar) {
            return null;
        }
        // Operand\Variable must not fold via unwrapCfgLiteralOperand (name → string "x") —
        // that mis-accepted `static $a = $param` as compile-time string on 8.2 (#22923).
        if ($this->functionStaticInitOperandReferencesLocal($terminal->defaultVar)) {
            return null;
        }
        // Share param-default folding (scalar/array literals, const fetch, unary, …) — Zend
        // zend_compile_static_variables() binds literals at compile time (#2286, #9351).
        $pseudo = new Op\Expr\Param(
            new Operand\Literal(''),
            new Op\Type\Mixed_(),
            false,
            false,
            $terminal->defaultVar,
            $terminal->defaultBlock
        );

        return $this->tryFoldParamDefaultSlot($pseudo, $block);
    }

    protected function tryBuildCompileTimeArrayFromExpr(
        Op\Expr\Array_ $expr,
        ?Block $block = null,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable
    {
        $unpackFlags = property_exists($expr, 'unpack') ? $expr->unpack : [];
        $byRefFlags = property_exists($expr, 'byRef') ? $expr->byRef : [];
        foreach ($byRefFlags as $refFlag) {
            if (!empty($refFlag)) {
                return null;
            }
        }
        $ht = new HashTable();
        $n = \count($expr->values);
        for ($i = 0; $i < $n; ++$i) {
            if (!empty($unpackFlags[$i])) {
                $spreadVm = $this->compileTimeVariableFromCfgArrayElement(
                    $expr->values[$i],
                    $block,
                    $defaultBlockChildren,
                    $materializeEnumCase
                );
                if (null === $spreadVm || !$spreadVm->is(Variable::TYPE_ARRAY)) {
                    return null;
                }
                $ht->spreadFrom($spreadVm->toArray());

                continue;
            }
            $valueVm = $this->compileTimeVariableFromCfgArrayElement(
                $expr->values[$i],
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
            if (null === $valueVm) {
                return null;
            }
            $keyOp = $expr->keys[$i] ?? null;
            if (null === $keyOp) {
                $ht->append($valueVm);
                continue;
            }
            if ($keyOp instanceof Operand\NullOperand) {
                $ht->append($valueVm);
                continue;
            }
            if ($keyOp instanceof Operand\Literal && null === $keyOp->value) {
                $ht->update('', $valueVm);
                continue;
            }
            $keyVm = $this->vmVariableFromCfgLiteralOperand($keyOp);
            if (null === $keyVm && null !== $block && [] !== $defaultBlockChildren) {
                $keyVm = $this->tryFoldCompileTimeOperandDefault(
                    $keyOp,
                    $block,
                    $defaultBlockChildren,
                    $materializeEnumCase
                );
            }
            if (null === $keyVm) {
                return null;
            }
            if ($keyVm->is(Variable::TYPE_INTEGER) || $keyVm->is(Variable::TYPE_FLOAT)) {
                $ht->updateIndex($keyVm->toInt(), $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_STRING)) {
                $ht->update($keyVm->toString(), $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_BOOLEAN)) {
                $ht->updateIndex($keyVm->toBool() ? 1 : 0, $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_NULL)) {
                $ht->update('', $valueVm);
            } else {
                return null;
            }
        }
        $vmArray = new Variable(Variable::TYPE_ARRAY);
        $vmArray->array($ht);

        return $vmArray;
    }

    protected function compileTimeVariableFromCfgArrayElement(
        Operand $operand,
        ?Block $block = null,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        $vm = $this->vmVariableFromCfgLiteralOperand($operand);
        if (null !== $vm) {
            return $vm;
        }
        if (null !== $block && [] !== $defaultBlockChildren) {
            $vm = $this->tryFoldCompileTimeOperandDefault(
                $operand,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
            if (null !== $vm) {
                return $vm;
            }
        }
        $nested = $this->unwrapCfgArrayExprOperand($operand);
        if (null !== $nested) {
            return $this->tryBuildCompileTimeArrayFromExpr(
                $nested,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }

        return null;
    }

    protected function unwrapCfgArrayExprOperand(Operand $operand): ?Op\Expr\Array_
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand instanceof Op\Expr\Array_ ? $operand : null;
    }

    protected function vmVariableFromCfgLiteralOperand(Operand $operand): ?Variable
    {
        // Named CVs / BoundVariable($this) unwrap to Literal(name) via unwrapCfgLiteralOperand —
        // that is the variable *name*, not a compile-time value. Folding it registers string
        // constants on the CV slot (e.g. const "this" on $this) so call-arg reads see a string
        // instead of the object (#28049, #28038, #22923).
        if (null !== Block::resolveVariableName($operand)) {
            return null;
        }
        $literal = $this->unwrapCfgLiteralOperand($operand);
        if (null === $literal) {
            return null;
        }
        $mappedType = Variable::mapFromType($literal->type ?? Type::mixed());
        if (Variable::TYPE_UNDEFINED === $mappedType) {
            if (\is_int($literal->value)) {
                $mappedType = Variable::TYPE_INTEGER;
            } elseif (\is_float($literal->value)) {
                $mappedType = Variable::TYPE_FLOAT;
            } elseif (\is_string($literal->value)) {
                $mappedType = Variable::TYPE_STRING;
            } elseif (\is_bool($literal->value)) {
                $mappedType = Variable::TYPE_BOOLEAN;
            } elseif (null === $literal->value) {
                $mappedType = Variable::TYPE_NULL;
            }
        }
        $return = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $return->string($literal->value, true);
                break;
            case Variable::TYPE_INTEGER:
                $return->int($literal->value);
                break;
            case Variable::TYPE_FLOAT:
                $return->float($literal->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $return->bool($literal->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                return null;
        }

        return $return;
    }

    protected function unwrapCfgLiteralOperand(Operand $operand): ?Operand\Literal
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }
        while ($operand instanceof Operand\Variable) {
            $operand = $operand->name;
            while ($operand instanceof Operand\Temporary && null !== $operand->original) {
                $operand = $operand->original;
            }
        }

        return $operand instanceof Operand\Literal ? $operand : null;
    }
}
