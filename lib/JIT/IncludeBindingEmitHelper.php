<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\BasicBlock;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\IncludeBindingJitHelper;

/**
 * LLVM emission for include local-binding materialize/restore (#10063, php-in-PHP).
 */
final class IncludeBindingEmitHelper
{
    /**
     * {@see __value__*} for {@see __value__readString} without boxing ephemeral rvalues twice (#846).
     */
    public static function valueStringSourcePtr(Context $context, Variable $callerVar): \PHPLLVM\Value
    {
        if (Variable::TYPE_VALUE === $callerVar->type && Variable::KIND_VARIABLE === $callerVar->kind) {
            $llvmType = $context->getStringFromType($callerVar->value->typeOf());
            if ('__value__*' === $llvmType || '__value__' === $llvmType) {
                return JitValueBox::normalizeValuePtr(
                    $context,
                    JitValueBox::pointer($context, $callerVar->value)
                );
            }
        }

        return JitValueBox::valuePtrFromVariable($context, $callerVar);
    }

    /**
     * Read boxed caller locals while preIncludeBb still dominates the caller alloca (#784).
     */
    public static function prepareCallerBinding(
        Context $context,
        BasicBlock $materializeBb,
        Variable $callerVar,
        ?string $name = null
    ): Variable {
        if (
            null !== $name
            && Variable::TYPE_VALUE === $callerVar->type
            && Variable::KIND_VALUE === $callerVar->kind
        ) {
            $slotBlock = $context->listUnpackAssignRootBlock ?? $context->jitEnclosingBlock;
            if (null !== $slotBlock) {
                $stable = IncludeBindingJitHelper::stableCallerValueSlot($context, $slotBlock, $name);
                if (null !== $stable) {
                    $callerVar = $stable;
                }
            }
            if (Variable::KIND_VALUE === $callerVar->kind) {
                $resolved = $context->resolveRefAliasName($name);
                if (isset($context->listUnpackAssignSlots[$resolved])) {
                    $slot = $context->listUnpackAssignSlots[$resolved];
                    if (
                        Variable::TYPE_VALUE === $slot->type
                        && Variable::KIND_VARIABLE === $slot->kind
                    ) {
                        $callerVar = $slot;
                    }
                }
            }
            if (Variable::KIND_VALUE === $callerVar->kind) {
                foreach (IncludeBindingJitHelper::variablesForScopedNameInCallerScopes($context, $name) as $slot) {
                    if (
                        Variable::TYPE_VALUE === $slot->type
                        && Variable::KIND_VARIABLE === $slot->kind
                    ) {
                        $callerVar = $slot;
                        break;
                    }
                }
            }
        }
        if (
            Variable::TYPE_STRING !== $callerVar->type
            || Variable::KIND_VARIABLE !== $callerVar->kind
        ) {
            if (Variable::TYPE_VALUE !== $callerVar->type) {
                return $callerVar;
            }
            $saved = $context->builder->getInsertBlock();
            $context->builder->positionAtEnd($materializeBb);
            $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
            $stringVar = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VARIABLE,
                $slot
            );
            $stringVar->initialize();
            $srcPtr = self::valueStringSourcePtr($context, $callerVar);
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $srcPtr
            );
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $context->builder->store($owned, $slot);
            $stringVar->addref();
            if (null !== $saved) {
                BasicBlockHelper::restoreInsertBlock($context, $saved);
            }

            return $stringVar;
        }
        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($materializeBb);
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $stringVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VARIABLE,
            $slot
        );
        $stringVar->initialize();
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->helper->loadValue($callerVar)
        );
        $context->builder->store($owned, $slot);
        $stringVar->addref();
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $stringVar;
    }

    public static function emitCalleeLocalBinding(
        Context $context,
        \PHPCompiler\JIT $jit,
        Operand $calleeOp,
        Variable $callerVar
    ): void {
        $bb = $context->builder->getInsertBlock();
        if (null === $bb) {
            return;
        }

        $calleeVar = $context->scope->variables->contains($calleeOp)
            ? $context->scope->variables[$calleeOp]
            : $context->getVariableFromOp($calleeOp);

        if (
            Variable::TYPE_STRING === $callerVar->type
            && Variable::TYPE_STRING === $calleeVar->type
            && Variable::KIND_VARIABLE === $calleeVar->kind
        ) {
            $context->builder->store(
                $context->helper->loadValue($callerVar),
                $calleeVar->value
            );
            $calleeVar->addref();
            $calleeVar->includeBinding = true;
            $context->setVariableOp($calleeOp, $calleeVar);

            return;
        }

        $jit->assignOperandForced($calleeOp, $callerVar);
        $context->getVariableFromOp($calleeOp)->includeBinding = true;
    }

    /**
     * Re-store caller locals materialized before the include entry (#784, #866).
     */
    public static function refreshInlineIncludeBindings(Context $context): void
    {
        if ([] === $context->inlineIncludeBindingRefreshStack) {
            return;
        }
        $frameIndex = \count($context->inlineIncludeBindingRefreshStack) - 1;
        $frame = $context->inlineIncludeBindingRefreshStack[$frameIndex];
        if ([] === $frame) {
            return;
        }
        $bb = $context->builder->getInsertBlock();
        if (null === $bb) {
            return;
        }
        if (null !== $bb->getTerminator()) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'include_refresh_cont');
        }
        foreach ($frame as $entry) {
            [$calleeOp, $prepared, $calleeVar, $compileTimeString] = array_pad($entry, 4, null);
            if (Variable::KIND_VARIABLE !== $calleeVar->kind) {
                continue;
            }
            if (null !== $compileTimeString && '' !== $compileTimeString) {
                $restored = $context->builder->load(
                    $context->constantStringFromString($compileTimeString)
                );
            } elseif (Variable::TYPE_STRING === $prepared->type) {
                $restored = $context->helper->loadValue($prepared);
            } else {
                continue;
            }
            self::storeIncludeBindingRestore($context, $calleeOp, $calleeVar, $restored);
            $bindingName = OperandName::resolve($calleeOp);
            if (null === $bindingName) {
                continue;
            }
            foreach ($context->scope->variables as $scopeOp) {
                if (OperandName::resolve($scopeOp) !== $bindingName) {
                    continue;
                }
                $scopeVar = $context->scope->variables[$scopeOp];
                if (Variable::KIND_VARIABLE !== $scopeVar->kind) {
                    continue;
                }
                self::storeIncludeBindingRestore($context, $scopeOp, $scopeVar, $restored);
            }
        }
    }

    private static function storeIncludeBindingRestore(
        Context $context,
        Operand $operand,
        Variable $var,
        \PHPLLVM\Value $restored
    ): void {
        if (Variable::TYPE_STRING === $var->type) {
            $context->builder->store($restored, $var->value);
            $var->addref();
            $var->includeBinding = true;
            $context->setVariableOp($operand, $var);

            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            $destPtr = JitValueBox::pointer($context, $var->value);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $destPtr,
                $restored
            );
            $var->includeBinding = true;
            $context->setVariableOp($operand, $var);
        }
    }
}
