<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_CLONE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner TYPE_CLONE case body
 * (php-src Zend/zend_vm_def.h ZEND_CLONE; Zend/zend_vm_execute.h clone
 * handlers; Zend/zend_lazy_objects.c zend_lazy_object_clone; zend_enum.c
 * uncloneable enums; zend_objects.c / zend_object_handlers clone_obj;
 * __clone magic in zend_object_handlers.c). Concern trait — same namespace
 * as parent so relative Frame / OpCode helpers resolve. Move-only; no new
 * C ABI. Companion helpers live in {@see ObjectPropertyMagicAndClone} and
 * {@see \PHPCompiler\VM\CloneSupport}.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait CloneDispatch
{
    /**
     * Execute TYPE_CLONE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeCloneDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $result = $frame->scope[$op->arg1];
        $src = $frame->scope[$op->arg2]->resolveIndirect();
        $uncloneableEnumClass = VM\EnumCaseSupport::uncloneableEnumClassForClone(
            $src,
            $this->context
        );
        if (null !== $uncloneableEnumClass) {
            $message = VM\CloneSupport::uncloneableObjectErrorMessage($uncloneableEnumClass);
            $catchFrame = $this->dispatchVmError($message, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        if (Variable::TYPE_OBJECT !== $src->type) {
            $catchFrame = $this->dispatchVmError(
                VM\CloneSupport::NON_OBJECT_ERROR_MESSAGE,
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        $srcObject = $src->toObject();
        $deniedCloneClass = VM\CloneSupport::uncloneableDeniedClass($srcObject, $this->context);
        if (null !== $deniedCloneClass) {
            $catchFrame = $this->dispatchVmError(
                VM\CloneSupport::uncloneableObjectErrorMessage($deniedCloneClass),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        $catchFrame = $this->enforceCloneVisibility($srcObject, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        // Zend/zend_lazy_objects.c zend_lazy_object_clone — init pending ghost/proxy
        // before clone so both original and clone are initialized (#29171).
        $catchFrame = $this->ensureLazyObjectInitialized($srcObject, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $cloned = $srcObject->cloneShallow();
        $this->invokeCloneObjectHandler($srcObject, $cloned);
        $catchFrame = $this->invokeCloneMagicMethod($cloned, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $result->object($cloned);
        return null;
    }
}
