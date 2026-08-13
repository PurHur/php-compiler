<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ScopeBuiltinRuntime;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * compact() local CV / script-global import gates (#10164, #21940, #30778).
 *
 * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(compact)
 */
final class ScopeBuiltinCompactLocalLlvm
{
    private static int $blockSeq = 0;

    public static function addByName(
        Context $context,
        Value $result,
        string $name,
        Variable $local
    ): void {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        // Assigned flags track stack KIND_VARIABLE CVs. {main} locals are KIND_VALUE
        // functionStaticGlobal heap boxes (#27118) — use runtime definedness (#30778).
        $stackCvGate = $block instanceof \PHPCompiler\Block
            && null !== $block->slotIndexForVariableName($name)
            && Variable::TYPE_VALUE === $local->type
            && Variable::KIND_VARIABLE === $local->kind
            && !$local->functionStaticGlobal;
        $scriptGlobalGate = $local->functionStaticGlobal && Variable::TYPE_VALUE === $local->type;
        if (!$stackCvGate && !$scriptGlobalGate) {
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            ScopeBuiltinEmitHelper::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $local);

            return;
        }

        $tag = 'cl'.(string) ++self::$blockSeq;
        $okBlock = BasicBlockHelper::append($context, 'compact_local_ok_'.$tag);
        $missBlock = BasicBlockHelper::append($context, 'compact_local_miss_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'compact_local_done_'.$tag);

        if ($stackCvGate) {
            $isAssigned = ScopeVariableAssignedFlags::isAssignedCondition(
                $context,
                ScopeVariableAssignedFlags::flagKey($context, $name)
            );
            $context->builder->branchIf($isAssigned, $okBlock, $missBlock);

            $context->builder->positionAtEnd($okBlock);
            $hasValueBlock = BasicBlockHelper::append($context, 'compact_local_has_value_'.$tag);
            $undefAfterAssignBlock = BasicBlockHelper::append($context, 'compact_local_undef_'.$tag);
            self::branchIfDefined($context, $local, $hasValueBlock, $undefAfterAssignBlock);

            $context->builder->positionAtEnd($hasValueBlock);
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            ScopeBuiltinEmitHelper::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $local);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($undefAfterAssignBlock);
            ScopeBuiltinRuntime::emitCompactUndefinedVariableWarning($context, $name);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($missBlock);
            ScopeBuiltinRuntime::emitCompactUndefinedVariableWarning($context, $name);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return;
        }

        self::branchIfDefined($context, $local, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $keyStr = $context->builder->load($context->constantStringFromString($name));
        ScopeBuiltinEmitHelper::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $local);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        ScopeBuiltinRuntime::emitCompactUndefinedVariableWarning($context, $name);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /** unset() leaves assigned flag set — runtime type check (#21940 / #30778). */
    private static function branchIfDefined(
        Context $context,
        Variable $local,
        BasicBlock $definedBlock,
        BasicBlock $undefinedBlock
    ): void {
        if (Variable::TYPE_VALUE !== $local->type) {
            $context->builder->branch($definedBlock);

            return;
        }
        if (Variable::KIND_VARIABLE !== $local->kind && !$local->functionStaticGlobal) {
            $context->builder->branch($definedBlock);

            return;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $local);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED, false)
        );
        $context->builder->branchIf($isUndef, $undefinedBlock, $definedBlock);
    }
}
