<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectEntry;

/**
 * VM TYPE_NEW dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner new-instance case body
 * (php-src Zend/zend_vm_def.h ZEND_NEW; zend_execute.c object init / ctor call
 * setup; Zend/zend_API.c object_and_properties_init). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait NewDispatch
{
    /**
     * Execute TYPE_NEW for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeNewDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $result = $frame->scope[$op->arg1];
        // Zend ZEND_NEW: classname operand is string or object (Z_OBJCE_P) (#30058).
        try {
            $rawName = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                $frame->scope[$op->arg2]
            );
            $lcname = $this->resolveClassScopeName($rawName, $frame);
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } catch (\LogicException $e) {
            throw new \LogicException($e->getMessage());
        }
        if (!isset($this->context->classes[$lcname])) {
            $rawLc = strtolower($rawName);
            if (!in_array($rawLc, ['self', 'static', 'parent'], true)) {
                $this->context->autoloadClass($rawName);
            }
        }
        if (!isset($this->context->classes[$lcname])) {
            $catchFrame = $this->dispatchVmError(
                $this->classNotFoundMessage($rawName),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        $class = $this->context->classes[$lcname];
        $reservedMsg = VM\ReservedBuiltinClass::userInstantiationErrorMessage($lcname);
        if (null !== $reservedMsg) {
            $catchFrame = $this->dispatchVmError($reservedMsg, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        if ($class->isEnum || $class->isAbstract) {
            $msg = $class->isEnum
                ? "Cannot instantiate enum {$class->name}"
                : "Cannot instantiate abstract class {$class->name}";
            $catchFrame = $this->dispatchVmError($msg, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        if ($class->isInterface) {
            $catchFrame = $this->dispatchVmError(
                "Cannot instantiate interface {$class->name}",
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        if ($class->isTrait) {
            $catchFrame = $this->dispatchVmError(
                "Cannot instantiate trait {$class->name}",
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        try {
            VM\ClassValidator::assertInstantiable($class);
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        $catchFrame = $this->enforceNewConstructorVisibility($class, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->emitClassInstantiationDeprecation($class, $frame);
        $object = new ObjectEntry($class);
        $this->initInstancePropertyDefaults($object);
        if (null !== $op->arg3 && VM\ExceptionSupport::classEntryImplementsThrowable($class, $this->context)) {
            $newLine = (int) $op->arg3;
            if ($newLine > 0) {
                $object->getProperty(VM\ExceptionSupport::PROP_LINE)->int($newLine);
            }
        }
        $result->object($object);
        $this->markScopeSlotInitialized($frame, (int) $op->arg1);
        $this->savePendingOutboundCallForInlineNew($frame);
        $frame->call = $object->constructor;
        $frame->callArgs = [$result];
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = $class->name.'::__construct';
        if (null === $frame->call) {
            $object->constructed = true;
            // No constructor body — clear the provisional Class::__construct label (#10009).
            $frame->builtinCalleeQualifiedMethod = null;
            $newResultSlot = (int) $op->arg1;
            if (!$this->isVmScopeSlotUsedByFollowingOps($frame, $newResultSlot)) {
                $this->releaseVmDeadScopeSlot($frame, $newResultSlot);
            }
        }

        return null;
    }
}
