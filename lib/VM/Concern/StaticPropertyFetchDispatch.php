<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_STATIC_PROPERTY_FETCH / TYPE_STATIC_PROPERTY_UNSET dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner static-property case body
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_STATIC_PROP_R/W / ZEND_UNSET_STATIC_PROP;
 * zend_object_handlers.c / zend_std_get_static_property). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only; no
 * new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait StaticPropertyFetchDispatch
{
    /**
     * Execute STATIC_PROPERTY_FETCH for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeStaticPropertyFetchDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
        $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
        if (!isset($this->context->classes[$lcClass])) {
            $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                ? $classOperand->toObject()->class->name
                : $classOperand->toString();
            if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                $this->context->autoloadClass($rawClass);
            }
        }
        if (!isset($this->context->classes[$lcClass])) {
            $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                ? $classOperand->toObject()->class->name
                : $classOperand->toString();

            return $this->raise("Unknown class for static property fetch: {$rawClass}", $frame);
        }
        $propNameRaw = $frame->scope[$op->arg3]->toString();
        $propName = strtolower($propNameRaw);
        $forWrite = $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
        $forIncDec = $this->propertyFetchDestUsedAsIncDec($frame, $op);
        $mutates = $forWrite || $forIncDec;
        if (!$mutates) {
            $visFrame = $this->enforceStaticPropertyReadVisibility($lcClass, $propNameRaw, $frame);
            if (null !== $visFrame) {
                return $visFrame;
            }
        }
        $storage = $this->resolveStaticPropertyStorage($lcClass, $propName);
        if (null === $storage) {
            $classLabel = $this->context->classes[$lcClass]->name;
            $catchFrame = $this->dispatchVmError(
                "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        if ($mutates) {
            $writeVisFrame = $this->enforceStaticPropertyWriteVisibility($lcClass, $propNameRaw, $frame);
            if (null !== $writeVisFrame) {
                return $writeVisFrame;
            }
            $writeMsg = $this->asymmetricStaticPropertyWriteMessage($lcClass, $propNameRaw, $frame);
            if (null !== $writeMsg) {
                $writeVisFrame = $this->dispatchVmError($writeMsg, $frame);
                if (null !== $writeVisFrame) {
                    return $writeVisFrame;
                }
            }
        }
        $readBeforeAssign = $forWrite && $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
        $hooks = $this->resolveStaticPropertyHooks($lcClass, $propName);
        if ($op->propertyHookCoalesceRead && !$mutates) {
            // Static ?? also rejects virtual write-only (#29240, zend_object_handlers.c).
            $catchFrame = $this->enforceWriteOnlyVirtualStaticPropertyRead(
                $lcClass,
                $propNameRaw,
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $dest = $frame->scope[$op->arg1];
            try {
                $this->fetchStaticPropertyForCoalesce($lcClass, $propNameRaw, $dest, $frame);
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                return $signal->catchFrame;
            }
            return null;
        }
        if (
            !$mutates
            && null !== $hooks
            && isset($hooks['get'])
            && !$this->isPropertyHookRawWrite($frame, $propNameRaw)
        ) {
            $hookValue = $this->fetchStaticPropertyWithHooks($lcClass, $propNameRaw, $hooks['get'], $frame);
            $dest = $frame->scope[$op->arg1];
            if (
                $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
                && isset($hooks['set'])
            ) {
                $catchFrame = $this->deliverHookedStaticPropertyDimWriteContainer(
                    $dest,
                    $hookValue,
                    $lcClass,
                    $propNameRaw,
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            } else {
                $dest->copyFrom($hookValue);
            }
            if (!$forWrite) {
                $this->emitStaticPropertyAccessDeprecation($lcClass, $propNameRaw, $frame);
            }
            return null;
        }
        if (
            $forWrite
            && null !== $hooks
            && isset($hooks['set'])
            && !$this->isPropertyHookRawWrite($frame, $propNameRaw)
        ) {
            if ($readBeforeAssign && isset($hooks['get'])) {
                $hookValue = $this->fetchStaticPropertyWithHooks($lcClass, $propNameRaw, $hooks['get'], $frame);
                $dest = $frame->scope[$op->arg1];
                $dest->copyFrom($hookValue);
                $dest->staticPropertyClassLc = $lcClass;
                $dest->objectPropertyName = $propNameRaw;
                $this->emitStaticPropertyAccessDeprecation($lcClass, $propNameRaw, $frame);
                return null;
            }
            $dest = $frame->scope[$op->arg1];
            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                VM\TypedPropertyCheck::prepareWritableByReference($storage);
            }
            $dest->indirect($storage);
            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                $dest->propertyRefAcquisition = true;
            } else {
                $dest->propertyAssignLvalue = true;
            }
            $dest->staticPropertyClassLc = $lcClass;
            $dest->objectPropertyName = $propNameRaw;
            $storage->staticPropertyClassLc = $lcClass;
            $storage->objectPropertyName = $propNameRaw;
            return null;
        }
        $dest = $frame->scope[$op->arg1];
        if (
            !$mutates
            && $this->isPropertyHookRawWrite($frame, $propNameRaw)
        ) {
            $backing = $this->hookedStaticPropertyBackingValue($lcClass, $propNameRaw);
            if (false !== $backing) {
                $dest->copyFromForClone($backing);
            } else {
                $dest->copyFromForClone($storage);
            }
            return null;
        }
        if (
            !$mutates
            && $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
        ) {
            $writeMsg = $this->asymmetricStaticPropertyWriteMessage($lcClass, $propNameRaw, $frame);
            if (null !== $writeMsg) {
                $writeVisFrame = $this->dispatchVmError($writeMsg, $frame);
                if (null !== $writeVisFrame) {
                    return $writeVisFrame;
                }
            }
        }
        if (!$mutates) {
            // BP_VAR_W dim-assign/append auto-inits or TypeError (#31770/#31819);
            // BP_VAR_RW ++/+= Errors (#31784).
            if ($this->propertyFetchAllowsTypedArrayDimAutoInit($frame, $op)) {
                VM\TypedPropertyCheck::tryInitEmptyArrayForDimWrite($storage);
            } else {
                VM\TypedPropertyCheck::assertReadable($storage);
            }
        }
        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
            VM\TypedPropertyCheck::prepareWritableByReference($storage);
        }
        $dest->indirect($storage);
        if ($forWrite) {
            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                $dest->propertyRefAcquisition = true;
            } else {
                $dest->propertyAssignLvalue = true;
            }
        }
        $dest->staticPropertyClassLc = $lcClass;
        $dest->objectPropertyName = $propNameRaw;
        if (!$mutates) {
            $this->emitStaticPropertyAccessDeprecation($lcClass, $propNameRaw, $frame);
        }
        return null;
    }

    /**
     * Execute STATIC_PROPERTY_UNSET for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeStaticPropertyUnsetDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
        $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
        if (!isset($this->context->classes[$lcClass])) {
            $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                ? $classOperand->toObject()->class->name
                : $classOperand->toString();
            if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                $this->context->autoloadClass($rawClass);
            }
        }
        if (!isset($this->context->classes[$lcClass])) {
            $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                ? $classOperand->toObject()->class->name
                : $classOperand->toString();

            return $this->raise("Unknown class for static property unset: {$rawClass}", $frame);
        }
        $propNameRaw = $frame->scope[$op->arg3]->toString();
        $propName = strtolower($propNameRaw);
        $storage = $this->resolveStaticPropertyStorage($lcClass, $propName);
        if (null === $storage) {
            $classLabel = $this->context->classes[$lcClass]->name;
            $catchFrame = $this->dispatchVmError(
                "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        $catchFrame = $this->enforceVirtualStaticPropertyHookUnset($lcClass, $propName, $propNameRaw, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        // Zend zend_std_unset_static_property: Error for all statics (#23691), not only typed (#6648).
        // Raw writes inside property-hook methods may still clear backing storage.
        $catchFrame = $this->enforceStaticPropertyUnset($lcClass, $propNameRaw, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->dispatchHookedStaticPropertyUnset($lcClass, $propName, $propNameRaw, $storage, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        return null;
    }
}
