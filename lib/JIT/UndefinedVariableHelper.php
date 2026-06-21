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
        $name = self::resolveTrackableName($op, $var);
        if (null === $name) {
            return;
        }
        ScopeVariableAssignedFlags::markAssigned(
            $context,
            ScopeVariableAssignedFlags::flagKey($context, $name)
        );
    }

    public static function guardBeforeRuntimeRead(Context $context, Operand $op, Variable $var): void
    {
        $name = self::resolveTrackableName($op, $var);
        if (null === $name) {
            return;
        }
        UndefinedVariableRuntime::ensureLinked($context);
        $key = ScopeVariableAssignedFlags::flagKey($context, $name);
        $isAssigned = ScopeVariableAssignedFlags::isAssignedCondition($context, $key);
        $fn = $context->builder->getInsertBlock()?->getParent();
        if (null === $fn) {
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
}
