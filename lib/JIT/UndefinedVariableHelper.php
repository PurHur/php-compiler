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
        if (Variable::KIND_VARIABLE !== $var->kind && !$var->functionStaticGlobal) {
            return null;
        }
        // Script-global heap boxes and typed string formals (#10360 / #31101 MiniWebApp $route).
        if ($var->functionStaticGlobal) {
            if (
                Variable::TYPE_VALUE !== $var->type
                && Variable::TYPE_STRING !== $var->type
            ) {
                return null;
            }
        } elseif (!in_array($var->type, [
            Variable::TYPE_VALUE,
            Variable::TYPE_STRING,
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::TYPE_NATIVE_BOOL,
        ], true)) {
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
        if (null === $name || self::isFormalParameter($context, $name)) {
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
        if (null === $name || self::isFormalParameter($context, $name)) {
            return;
        }
        $resolved = $context->resolveRefAliasName($name);
        // foreach ($a as &$v) binds a live ref CV — reads/writes must not warn (#24010 / i11).
        if (isset($context->foreachByRefLocalNames[$resolved])) {
            return;
        }
        // Arrow-fn / closure use() captures are bound in the LLVM prologue before body
        // code runs; emitting ZEND_CHECK_UNDEFINED_VAR for them is spurious (#10304, #24106).
        $block = $context->jitCurrentBlock;
        if (null !== $block) {
            if (in_array($name, $block->closureCaptureSlotNames, true)) {
                return;
            }
            if ($context->isForeachByRefLocalName($name, $block)) {
                return;
            }
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
        if (null === $name || self::isFormalParameter($context, $name)) {
            return;
        }
        self::emitAssignedFlagGuard($context, $name);
    }

    /** Script-global heap box read by resolved CV name ({main} / global import, #36081). */
    public static function guardBeforeScriptGlobalName(Context $context, string $name): void
    {
        if (self::shouldSkipGuards()) {
            return;
        }
        if ('' === $name || 'this' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return;
        }
        self::emitAssignedFlagGuard($context, $name);
    }

    /** Formal parameters are always defined — Zend never ZEND_CHECK_UNDEFINED_VAR on CV params. */
    private static function isFormalParameter(Context $context, string $name): bool
    {
        $resolved = $context->resolveRefAliasName($name);
        $block = $context->jitFunctionRootBlock
            ?? $context->jitEnclosingBlock
            ?? $context->jitCurrentBlock;
        if ($block instanceof \PHPCompiler\Block) {
            if (null !== $block->paramSlotForName($resolved) || null !== $block->paramSlotForName($name)) {
                return true;
            }
        }
        $active = strtolower($context->activeFunction);
        if ('' !== $active && isset($context->functionProxies[$active])) {
            $proxy = $context->functionProxies[$active];
            if ($proxy instanceof Call\Native) {
                return in_array($resolved, $proxy->paramNames, true)
                    || in_array($name, $proxy->paramNames, true);
            }
        }

        return false;
    }

    private static function emitAssignedFlagGuard(Context $context, string $name): void
    {
        if (self::isFormalParameter($context, $name)) {
            return;
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $savedInsert) {
            return;
        }
        UndefinedVariableRuntime::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
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
