<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_BOOLEAN_NOT / TYPE_EMPTY* dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_vm_def.h ZEND_BOOL_NOT / ZEND_ISSET_ISEMPTY_VAR /
 * ZEND_ISSET_ISEMPTY_DIM_OBJ / ZEND_ISSET_ISEMPTY_PROP_OBJ /
 * ZEND_ISSET_ISEMPTY_CV; zend_execute.c empty handlers). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait EmptyAndBooleanNotDispatch
{
    /**
     * Execute BOOLEAN_NOT / EMPTY / EMPTY_* for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeEmptyAndBooleanNotDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_BOOLEAN_NOT:
            $value = !($frame->scope[$op->arg2]->toBool());
            $dst = $frame->scope[$op->arg1];
            $dst->bool($value);
            return null;
        case OpCode::TYPE_EMPTY:
            if ($this->isUnboundThisSlot($frame, (int) $op->arg2)) {
                $frame->scope[$op->arg1]->bool(true);
                return null;
            }
            $v = $frame->scope[$op->arg2]->resolveIndirect();
            if (VM\TypedPropertyCheck::isUninitialized($v)) {
                $frame->scope[$op->arg1]->bool(true);
                return null;
            }
            $frame->scope[$op->arg1]->bool(!ext\standard\boolval::isTruthy($v));
            return null;
        case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
            $dst = $frame->scope[$op->arg1];
            $container = $frame->scope[$op->arg2]->resolveIndirect();
            [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($frame->scope[$op->arg3], $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforcePropertyName($propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if (Variable::TYPE_ENUM_CASE === $container->type) {
                $dst->bool(VM\EnumCaseSupport::emptyPropertyOnCase(
                    $container->toEnumCase(),
                    $propName,
                    $this->context,
                    $frame
                ));
                return null;
            }
            if (Variable::TYPE_OBJECT !== $container->type) {
                $dst->bool(true);
                return null;
            }
            $object = $container->toObject();
            if (VM\EnumCaseSupport::isEnumCase($object)) {
                $enum = $object->class;
                if (!VM\EnumCaseSupport::propertyExistsOnCase($enum, $propName)) {
                    $dst->bool(true);
                    return null;
                }
                $prop = VM\EnumCaseSupport::getProperty($object, $propName, $this->context, $frame);
                $dst->bool(!ext\standard\boolval::isTruthy($prop));
                return null;
            }
            $catchFrame = $this->ensureLazyObjectInitialized($object, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $object = VM\LazyObjectSupport::getLazyInstance($object);
            $catchFrame = $this->emptyObjectProperty(
                $object,
                $propName,
                $frame,
                $dst
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
            $dst = $frame->scope[$op->arg1];
            $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
            if (!isset($this->context->classes[$lcClass])) {
                $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
                $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                    ? $classOperand->toObject()->class->name
                    : $classOperand->toString();
                if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                    $this->context->autoloadClass($rawClass);
                }
            }
            if (!isset($this->context->classes[$lcClass])) {
                $dst->bool(true);
                return null;
            }
            $propNameRaw = $frame->scope[$op->arg3]->toString();
            $catchFrame = $this->emptyStaticProperty($lcClass, $propNameRaw, $frame, $dst);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        case OpCode::TYPE_EMPTY_DIMENSION:
            $dst = $frame->scope[$op->arg1];
            $catchFrame = $this->evaluateEmptyDimension(
                $frame->scope[$op->arg2],
                $frame->scope[$op->arg3],
                $frame,
                $dst
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }

        throw new \LogicException('executeEmptyAndBooleanNotDispatch: unexpected opcode '.$op->type);
    }
}
