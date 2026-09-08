<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectLifetime;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefRegistry;

/**
 * VM TYPE_UNSET dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner unset case body
 * (php-src Zend/zend_vm_def.h ZEND_UNSET_CV / ZEND_UNSET_VAR / ZEND_UNSET_DIM /
 * ZEND_UNSET_OBJ; zend_object_handlers.c unset_property / ArrayAccess::offsetUnset).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait UnsetDispatch
{
    /**
     * Execute TYPE_UNSET for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeUnsetDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if (null === $op->arg3) {
            $this->releaseVmStatementDeadTemps($frame, (int) $op->arg2);
            if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                $slot = $frame->scope[$op->arg2];
                $unsetTarget = $slot->resolveIndirect();
                $globalBinding = $slot->directIndirectTarget();
                $ownedNamedUnset = null !== $frame->block
                    && $frame->block->isNamedVariableSlot((int) $op->arg2)
                    && (
                        !$slot->isIndirect()
                        || (
                            null !== $globalBinding
                            && $this->context->isGlobalStorage($globalBinding)
                        )
                    );
                if ($ownedNamedUnset) {
                    ObjectLifetime::invokeUnsetDestructor($this, $unsetTarget);
                }
                if (null !== $frame->block && $frame->block->isMainScript()) {
                    foreach ($frame->block->eachNamedScopeSlot() as [$globalName, $namedSlot]) {
                        if ($namedSlot === (int) $op->arg2) {
                            $this->context->clearGlobalByName($globalName);
                            break;
                        }
                    }
                } elseif (
                    Variable::TYPE_OBJECT === $unsetTarget->type
                    && isset($unsetTarget->object)
                    && $unsetTarget->object->refCount <= 1
                ) {
                    WeakRefRegistry::clearForObject($unsetTarget->toObject()->id);
                }
                // Break the local/reference binding only — never destroy the shared
                // target (Zend unset on ref; foreach &$v cleanup #4997, #3517).
                if (
                    null !== $globalBinding
                    && $this->context->isGlobalStorage($globalBinding)
                ) {
                    $globalBinding->reset();
                    $globalBinding->type = Variable::TYPE_UNDEFINED;
                }
                $slot->reset();
                $slot->type = Variable::TYPE_UNDEFINED;
            }

            return null;
        }
        $containerSlot = $frame->scope[$op->arg2];
        $container = $containerSlot->resolveIndirect();
        $key = isset($frame->block->constants[$op->arg3])
            ? $frame->block->constants[$op->arg3]
            : $frame->scope[$op->arg3];
        if (Variable::TYPE_ENUM_CASE === $container->type) {
            [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($key, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforcePropertyName($propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $enumEntry = $container->toEnumCase()->enumClass;
            $readonlyMsg = EnumCaseSupport::readonlyPseudoPropertyViolationMessage(
                $enumEntry,
                $propName,
                true
            );
            if (null !== $readonlyMsg) {
                $catchFrame = $this->dispatchVmError($readonlyMsg, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }

            return null;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            $object = $container->toObject();
            if (!$op->unsetOnProperty) {
                // unset($obj[$k]) — ArrayAccess::offsetUnset, else Zend Error
                // (DOMNodeList/DOMNamedNodeMap have no unset_dimension; #23304).
                if ($this->objectImplementsArrayAccess($object)) {
                    $catchFrame = $this->invokeArrayAccessOffsetUnset($object, $key, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }

                    return null;
                }
                $catchFrame = $this->dispatchVmError(
                    VM\VmUnset::cannotUseObjectAsArrayMessage($object->class->name),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return null;
            }
            // unset($sxe->child) — ext/simplexml via SimpleXmlVmRuntimeSupport (#36204).
            if ($op->unsetOnProperty && VM\SimpleXmlVmRuntimeSupport::isUnsetChildPropertySubject($object)) {
                [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($key, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->enforcePropertyName($propName, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                VM\SimpleXmlVmRuntimeSupport::unsetChildProperty($object, $propName);

                return null;
            }
            [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($key, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforcePropertyName($propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            // unset($incomplete->prop) — Error like write (#19632).
            if (VM\IncompleteClassSupport::isIncomplete($object)) {
                $catchFrame = $this->dispatchVmError(
                    VM\IncompleteClassSupport::modifyErrorMessage($object),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            if (EnumCaseSupport::isEnumCase($object)) {
                $readonlyMsg = EnumCaseSupport::readonlyPseudoPropertyViolationMessage(
                    $object->class,
                    $propName,
                    true
                );
                if (null !== $readonlyMsg) {
                    $catchFrame = $this->dispatchVmError($readonlyMsg, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }

                    return self::EXCEPTION;
                }

                return null;
            }
            // Readonly beats asymmetric set-visibility on unset (zend_object_handlers.c, #29273).
            // PHP 8.4 implicit protected(set) on readonly must not win the Error wording.
            $catchFrame = $this->enforceReadonlyPropertyUnset($object, $propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            // unset() follows set-visibility (zend_object_handlers.c, #23338).
            $catchFrame = $this->enforceAsymmetricPropertyUnset($object, $propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforceVirtualPropertyHookUnset($object, $propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->dispatchHookedInstancePropertyUnset($object, $propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        // ZEND_UNSET_OBJ on non-object (array/scalar/null/…) — silent no-op
        // (zend_vm_def.h; #30065). Must run before ARRAY / UNSET_DIM paths so
        // unset($arr->prop) does not delete an array key and false stays silent.
        if (VM\VmUnset::isNonObjectUnsetPropNoop($op->unsetOnProperty, $container->type)) {
            return null;
        }
        if (Variable::TYPE_ARRAY === $container->type) {
            $keyResolved = $key->resolveIndirect();
            if (
                Variable::TYPE_STRING === $keyResolved->type
                && null !== $frame->block
                && $this->isGlobalsSuperglobalUnset($frame, (int) $op->arg2, $keyResolved->toString())
            ) {
                $this->context->unsetGlobalsTableKey($keyResolved->toString());

                return null;
            }
            try {
                $container->separateArrayForWrite();
                $container = $containerSlot->resolveIndirect();
                $container->toArray()->offsetUnset($key, $frame);
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }

            return null;
        }
        // ZEND_UNSET_DIM: null/undef silent no-op; false → Deprecated only (leaves false)
        // — does not promote to array (zend_vm_def.h; #30099).
        if (VM\VmUnset::isNullOrUndefUnsetDimNoop($container)) {
            return null;
        }
        if (VM\VmUnset::isFalseUnsetDimDeprecated($container)) {
            $this->context->errors->internalDeprecated(
                TypeCheck::FALSE_TO_ARRAY_DEPRECATED_MESSAGE,
                $this->context,
                $frame,
                '' !== $frame->scriptPath ? $frame->scriptPath : null
            );

            return null;
        }
        $unsetDimMsg = Variable::TYPE_STRING === $container->type
            ? VM\VmUnset::ERROR_STRING_OFFSET
            : VM\VmUnset::ERROR_NON_ARRAY;
        $catchFrame = $this->dispatchUnsetDimNonContainerError($frame, $unsetDimMsg);
        if (null !== $catchFrame) {
            return $catchFrame;
        }

        return null;
    }
}
