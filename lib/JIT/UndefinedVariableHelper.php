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
        if (Variable::TYPE_VALUE !== $var->type) {
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
        $name = self::resolveTrackableName($op, $var);
        if (null === $name) {
            return;
        }
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
        $flag = \getenv('PHP_COMPILER_SELFHOST_AOT');

        return '0' === $flag;
    }
}
