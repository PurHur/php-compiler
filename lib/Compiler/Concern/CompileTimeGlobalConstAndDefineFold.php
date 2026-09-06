<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\ClassConstName;
use PHPCompiler\VM\Variable;

/**
 * Compile-time global const / define() / enum-case prescan folding (#36387).
 *
 * Extracted from {@see CompileTimeFold} so gen-0 split-TU can hollow a smaller Concern TU.
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_compile.c + zend_constants.c / zend_builtin_functions.c
 * define()/const folding — move-only, no new C ABI.
 */
trait CompileTimeGlobalConstAndDefineFold
{
    protected function registerNullConstantSlot(Block $block, Operand $operand): int
    {
        return $block->registerConstant($operand, new Variable(Variable::TYPE_NULL));
    }

    protected function tryFoldGlobalConstFetch(Op\Expr\ConstFetch $expr): ?Variable
    {
        $name = $this->staticNameFromOperand($expr->name);
        if (null === $name) {
            return null;
        }
        $vm = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetch($name);
        if (null !== $vm) {
            return $vm;
        }
        $lc = strtolower($name);
        if ('null' === $lc) {
            return new Variable(Variable::TYPE_NULL);
        }
        if ('true' === $lc) {
            $v = new Variable(Variable::TYPE_BOOLEAN);
            $v->bool(true);

            return $v;
        }
        if ('false' === $lc) {
            $v = new Variable(Variable::TYPE_BOOLEAN);
            $v->bool(false);

            return $v;
        }
        $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($name);
        if (null !== $errorInt) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int($errorInt);

            return $v;
        }
        $lc = strtolower($name);
        if ('inf' === $lc) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float(INF);

            return $v;
        }
        if ('nan' === $lc) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float(NAN);

            return $v;
        }
        if (isset($this->compileTimeGlobalConsts[$lc])) {
            $value = new Variable();
            $value->copyFrom($this->compileTimeGlobalConsts[$lc]);

            return $value;
        }
        $stdlibInt = \PHPCompiler\ext\standard\StdlibConstants::coreIntByName($lc);
        if (null !== $stdlibInt) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int($stdlibInt);

            return $v;
        }
        // M_PI / M_E / … — same map as VM\Context::constantFetch (#27249).
        $stdlibFloat = \PHPCompiler\ext\standard\StdlibConstants::CORE_FLOAT_BY_NAME[$lc] ?? null;
        if (null !== $stdlibFloat) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float($stdlibFloat);

            return $v;
        }
        $dateStr = \PHPCompiler\ext\standard\DateConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $dateStr) {
            $v = new Variable(Variable::TYPE_STRING);
            $v->string($dateStr);

            return $v;
        }
        $stdlibStr = \PHPCompiler\ext\standard\StdlibConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $stdlibStr) {
            $v = new Variable(Variable::TYPE_STRING);
            $v->string($stdlibStr);

            return $v;
        }

        return null;
    }

    /**
     * Pre-register enum `case` singletons for class/global const folding (#15737).
     *
     * Class/interface/trait bodies are hoisted before enum DECLARE opcodes; prescan
     * mirrors {@see compileEnum} metadata so {@code E::A} folds when enum is later in source.
     *
     * @param list<Op> $ops
     */
    protected function prescanCompileTimeEnumCases(array $ops): void
    {
        foreach ($ops as $child) {
            if ($child instanceof Op\Stmt\Enum_) {
                $this->prescanEnumCaseConstants($child);
            }
        }
    }

    protected function prescanEnumCaseConstants(Op\Stmt\Enum_ $enum): void
    {
        $enumName = $this->staticNameFromOperand($enum->name);
        if (null === $enumName) {
            return;
        }
        $enumLc = strtolower(ltrim($enumName, '\\'));
        $displayName = ltrim($enumName, '\\');
        $backedTypeName = null;
        if (null !== $enum->backedType && $enum->backedType instanceof Op\Type\Literal) {
            $backedTypeName = $enum->backedType->name;
        }
        if (!isset($this->compileTimeEnumBackedTypes[$enumLc])) {
            $this->compileTimeEnumBackedTypes[$enumLc] = $backedTypeName;
        }
        if (!isset($this->compileTimeEnumCaseConstNames[$enumLc])) {
            $this->compileTimeEnumCaseConstNames[$enumLc] = [];
        }
        if (!isset($this->runtimeEnumCaseConsts[$enumLc])) {
            $this->runtimeEnumCaseConsts[$enumLc] = [];
        }

        $prevClassLc = $this->compilingClassLc;
        $prevDisplay = $this->compilingClassDisplayName;
        $this->compilingClassLc = $enumLc;
        $this->compilingClassDisplayName = $displayName;

        foreach ($enum->stmts->children as $stmt) {
            if (!$stmt instanceof Op\Terminal\Const_ || !$this->cfgTerminalConstIsEnumCase($stmt)) {
                continue;
            }
            $caseName = $this->staticNameFromOperand($stmt->name);
            if (null === $caseName) {
                continue;
            }
            $lcCase = ClassConstName::key($caseName);
            if (isset($this->runtimeEnumCaseConsts[$enumLc][$lcCase])) {
                continue;
            }
            $backing = $this->vmVariableFromCfgLiteralOperand($stmt->value);
            if (null === $backing) {
                if (null !== $backedTypeName) {
                    continue;
                }
                $backing = new Variable(Variable::TYPE_NULL);
                $backing->null();
            }
            $this->runtimeEnumCaseConsts[$enumLc][$lcCase] = $this->compileTimeEnumCaseVar(
                $displayName,
                $caseName,
                $backing,
                $backedTypeName
            );
            $this->compileTimeEnumCaseConstNames[$enumLc][$lcCase] = true;
        }

        $this->compilingClassLc = $prevClassLc;
        $this->compilingClassDisplayName = $prevDisplay;
    }

    /**
     * Pre-register global `const` and literal define() for default-value folding (#6542).
     *
     * @param list<Op> $ops
     */
    protected function prescanCompileTimeGlobalConsts(array $ops, Block $block): void
    {
        foreach ($ops as $child) {
            if ($child instanceof Op\Terminal\Const_) {
                $this->prescanGlobalConstTerminal($child, $block);
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall) {
                $this->prescanDefineFuncCall($child, $block);
            }
        }
    }

    protected function prescanGlobalConstTerminal(Op\Terminal\Const_ $const, Block $block): void
    {
        $this->rejectReservedGlobalConstName($const);
        $name = $this->staticNameFromOperand($const->name);
        if (null === $name) {
            return;
        }
        $valueSlot = $this->tryFoldGlobalConstValueSlot($const, $block);
        if (null === $valueSlot || !isset($block->constants[$valueSlot])) {
            return;
        }
        $this->storeCompileTimeGlobalConst($name, $block->constants[$valueSlot]);
    }

    protected function prescanDefineFuncCall(Op\Expr\FuncCall $expr, Block $block): void
    {
        $fnName = $this->staticNameFromOperand($expr->name);
        if (null === $fnName || 'define' !== strtolower($fnName)) {
            return;
        }
        if (count($expr->args) < 2 || count($expr->args) > 3) {
            return;
        }
        $constNameArg = $expr->args[0];
        $valueArg = $expr->args[1];
        if (!$constNameArg instanceof Operand\Literal) {
            return;
        }
        if (Variable::TYPE_STRING !== Variable::mapFromType($constNameArg->type)) {
            return;
        }
        // Runtime define('NAME', expr) must not seed compileTimeGlobalConsts (#17676).
        if (!$valueArg instanceof Operand\Literal) {
            return;
        }
        $constName = $constNameArg->value;
        if (!is_string($constName) || '' === $constName || str_contains($constName, '::')) {
            return;
        }
        if ($this->defineValueRequiresRuntimeEvaluation($valueArg, $block)) {
            return;
        }
        // define() inside a function/method registers when that function runs (zend_constants.c).
        // Seeding compileTimeGlobalConsts here would fold the name in {main} before the call (#32039).
        if (!$this->compileBlockIsFileScopeMain($block)) {
            return;
        }
        $vm = $this->tryFoldDefineValueOperand($valueArg, $block);
        if (null === $vm) {
            return;
        }
        $this->storeCompileTimeGlobalConst($constName, $vm);
    }

    /**
     * Fold define('NAME', expr) value operands for compile-time const registration (#5409).
     */
    protected function tryFoldDefineValueOperand(Operand $valueArg, Block $block): ?Variable
    {
        $vm = $this->vmVariableFromCfgLiteralOperand($valueArg);
        if (null !== $vm) {
            return $vm;
        }
        if (null === $block->orig) {
            return null;
        }
        $root = $this->unwrapOperandChain($valueArg);
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($child->result, $root)
            ) {
                if ($this->cfgArrayIsObjectCastSourceForOperand($child->result, $root, $block)) {
                    continue;
                }
                return $this->tryBuildCompileTimeArrayFromExpr($child);
            }
            if (!$child instanceof Op\Expr || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault($child, $block, [$child], true);
            if (null !== $vm) {
                return $vm;
            }
        }

        return null;
    }

    /** define('N', (object)[...]) and other runtime-only values must not prescan (#17676). */
    protected function defineValueRequiresRuntimeEvaluation(Operand $valueArg, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($valueArg);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            if ($child instanceof Op\Expr\Cast) {
                return true;
            }
        }

        return false;
    }

    protected function storeCompileTimeGlobalConst(string $name, Variable $value): void
    {
        $lc = strtolower($name);
        // File-scope `const true` is a compile fatal (#32228); define('true') must not
        // fold later ConstFetch of the special name (zend_get_special_const).
        if ('true' === $lc || 'false' === $lc || 'null' === $lc) {
            return;
        }
        if (isset($this->compileTimeGlobalConsts[$lc])) {
            return;
        }
        $stored = new Variable();
        $stored->copyFrom($value);
        $this->compileTimeGlobalConsts[$lc] = $stored;
    }

    /**
     * File-level {main} (not function/method/closure bodies).
     *
     * Literal define() may fold ConstFetch only in this scope (#6542). Nested define()
     * still executes at run time (#32039, zend_builtin_functions.c).
     */
    private function compileBlockIsFileScopeMain(Block $block): bool
    {
        $func = $block->func;
        if (null === $func) {
            return true;
        }

        return '{main}' === $func->name && null === $func->class;
    }

}

