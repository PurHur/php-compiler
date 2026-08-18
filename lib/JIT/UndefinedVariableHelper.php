<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\UndefinedVariableRuntime;
use PHPCfg\Operand;

/**
 * JIT guards for undefined scope-variable reads (#10360, Zend/zend_execute.c).
 *
 * SSOT: {@see \PHPCompiler\VM\UndefinedVariableJitHelper}
 */
final class UndefinedVariableHelper
{
    public static function resolveTrackableName(Operand $op, Variable $var): ?string
    {
        // VALUE boxes and native STRING slots (typed formals) — #10360 / #31101 MiniWebApp $route.
        if (
            Variable::TYPE_VALUE !== $var->type
            && Variable::TYPE_STRING !== $var->type
        ) {
            return null;
        }
        if (Variable::KIND_VARIABLE !== $var->kind && !$var->functionStaticGlobal) {
            return null;
        }
        $name = OperandName::resolve($op);
        if (null === $name || '' === $name || 'this' === $name) {
            return null;
        }

        return $name;
    }

    public static function markAssigned(Context $context, Operand $op, Variable $var): void
    {
        if (self::shouldSkipGuards()) {
            return;
        }
        $name = self::resolveTrackableName($op, $var);
        if (null === $name) {
            return;
        }
        if (null === BasicBlockHelper::tryGetInsertBlock($context)) {
            return;
        }
        ScopeVariableAssignedFlags::markAssigned(
            $context,
            ScopeVariableAssignedFlags::flagKey($context, $name)
        );
    }

    public static function guardBeforeRuntimeRead(Context $context, Operand $op, Variable $var): void
    {
        if (self::shouldSkipGuards()) {
            return;
        }
        // Inlined include bindings inherit the caller's locals (PHP include scope) — do not
        // re-warn as undefined (#31101 MiniWebApp layout $title after Router::renderHome).
        if ($var->includeBinding) {
            return;
        }
        $name = self::resolveTrackableName($op, $var);
        if (null === $name) {
            return;
        }
        $resolved = $context->resolveRefAliasName($name);
        // Name already bound in this function (param/local) — treat as assigned even when
        // the init flag global was missed (typed string formals / include inlines) (#31101).
        if (isset($context->namedVariableBindings[$resolved])) {
            return;
        }
        self::emitAssignedFlagGuard($context, $name);
    }

    /**
     * ZEND_CHECK_UNDEFINED_VAR for named CVs, including bound-but-unassigned locals.
     *
     * TYPE_ASSIGN of `@$undef` inside a function/closure must still record
     * error_get_last() (Zend EG(last_error) under ZEND_BEGIN_SILENCE, #32041).
     * {@see guardBeforeRuntimeRead} skips names already in namedVariableBindings
     * (#31101); assign RHS cannot.
     */
    public static function guardBeforeNamedLocalRead(Context $context, Operand $op, Variable $var): void
    {
        if (self::shouldSkipGuards()) {
            return;
        }
        if ($var->includeBinding) {
            return;
        }
        $name = self::resolveTrackableName($op, $var);
        if (null === $name) {
            return;
        }
        self::emitAssignedFlagGuard($context, $name);
    }

    private static function emitAssignedFlagGuard(Context $context, string $name): void
    {
        UndefinedVariableRuntime::ensureLinked($context);
        if (null === BasicBlockHelper::tryGetInsertBlock($context)) {
            return;
        }
        $key = ScopeVariableAssignedFlags::flagKey($context, $name);
        $isAssigned = ScopeVariableAssignedFlags::isAssignedCondition($context, $key);
        try {
            $fn = BasicBlockHelper::parentFunction($context);
        } catch (\LogicException) {
            return;
        }
        $warnBlock = $fn->appendBasicBlock('undef_var_warn');
        $doneBlock = $fn->appendBasicBlock('undef_var_done');
        $context->builder->branchIf($isAssigned, $doneBlock, $warnBlock);
        $context->builder->positionAtEnd($warnBlock);
        UndefinedVariableRuntime::emitWarningForName($context, $name);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }

    /** Nested php-in-PHP helper compiles set PHP_COMPILER_SELFHOST_AOT=0 (#10524). */
    private static function shouldSkipGuards(): bool
    {
        // Skip only while NestedJIT is actively compiling a helper (#10524).
        // Do not key off PHP_COMPILER_SELFHOST_AOT=0 leftovers from JitVmHelperLink (#30779).
        return NestedJitCompileScope::isActive();
    }
}
