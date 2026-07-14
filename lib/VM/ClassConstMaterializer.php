<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\ext\spl\SplDualIteratorStorage;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;
use PHPCompiler\VM as VmEngine;

/**
 * Evaluate class constant initializer opcodes (e.g. {@code new stdClass()}) at class definition.
 *
 * @see Zend/zend_compile.c zend_compile_const_expr (php-src)
 */
final class ClassConstMaterializer
{
    public static function materializeSlot(
        VmEngine $vm,
        Block $bodyBlock,
        int $valueSlot,
        ?string $declaringClassName = null
    ): Variable {
        $frame = $bodyBlock->getFrame($vm->context);
        $entry = self::declaringClassEntry($vm, $declaringClassName);
        foreach ($bodyBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && $valueSlot === $op->arg2) {
                break;
            }
            if ($vm->isClassBodyConstInitOpcode($op->type)) {
                $vm->executeClassBodyConstInitOpcode($frame, $op);
                continue;
            }
            if (null !== $entry && ClassConstExpr::isSupportedOpcode($op->type)) {
                ClassConstExpr::execute($vm->context, $frame, $bodyBlock, $op, $entry);
                continue;
            }
            if (null !== $entry && OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                self::registerPriorClassConst($vm->context, $bodyBlock, $frame, $entry, $op);
            }
        }
        if (!isset($frame->scope[$valueSlot])) {
            throw new \LogicException('Class constant value must be a compile-time constant');
        }

        return self::detachConstantValue($frame->scope[$valueSlot]);
    }

    private static function declaringClassEntry(VmEngine $vm, ?string $declaringClassName): ?ClassEntry
    {
        if (null === $declaringClassName || '' === $declaringClassName) {
            return null;
        }
        $name = ltrim($declaringClassName, '\\');
        $lc = strtolower($name);
        if (isset($vm->context->classes[$lc])) {
            return $vm->context->classes[$lc];
        }
        $entry = new ClassEntry($name);
        $vm->context->classes[$lc] = $entry;

        return $entry;
    }

    private static function registerPriorClassConst(
        Context $context,
        Block $bodyBlock,
        Frame $frame,
        ClassEntry $entry,
        OpCode $op
    ): void {
        $name = strtolower($frame->scope[$op->arg1]->toString());
        if (isset($bodyBlock->constants[$op->arg2])) {
            $const = $bodyBlock->constants[$op->arg2];
            if (!$const->is(Variable::TYPE_NULL)) {
                $value = new Variable();
                $value->copyFrom($const);
                $entry->constants[$name] = EnumCaseSupport::materializeConstantValue($context, $value);

                return;
            }
        }
        if (isset($frame->scope[$op->arg2])) {
            $entry->constants[$name] = EnumCaseSupport::materializeConstantValue(
                $context,
                $frame->scope[$op->arg2]
            );
        }
    }

    /**
     * Store an immortal copy of a class constant value (shared identity on fetch).
     */
    public static function detachConstantValue(Variable $src): Variable
    {
        $src = $src->resolveIndirect();
        $stored = new Variable($src->type);
        switch ($src->type) {
            case Variable::TYPE_NULL:
                $stored->null();
                break;
            case Variable::TYPE_STRING:
                $str = $src->optionalScalarString();
                if (null === $str) {
                    $stored->undefined();
                    break;
                }
                $stored->string($str);
                break;
            case Variable::TYPE_INTEGER:
                if ($src->isStreamResource()) {
                    $stored->legacyStreamHandle($src->toInt());
                } elseif ($src->isDirResource()) {
                    $stored->legacyDirHandle($src->toInt());
                } else {
                    $int = $src->optionalScalarInt();
                    if (null === $int) {
                        $stored->undefined();
                        break;
                    }
                    $stored->int($int);
                }
                break;
            case Variable::TYPE_FLOAT:
                $stored->float($src->toFloat());
                break;
            case Variable::TYPE_BOOLEAN:
                $stored->bool($src->toBool());
                break;
            case Variable::TYPE_OBJECT:
                $srcObj = $src->toObject();
                if (null !== $srcObj->closureState) {
                    $stored->copyFrom($src);
                    break;
                }
                // Builtin instances keep object identity — SplDualIteratorStorage keys on ObjectEntry::id (#17721).
                if ($srcObj->class->isInternal) {
                    $stored->object($srcObj);
                    break;
                }
                // Enum case singletons — preserve magic name/value metadata (#17743, #17744, Zend/zend_enum.c).
                if (EnumCaseSupport::isEnumCase($srcObj)) {
                    $backing = new Variable();
                    $backing->null();
                    if (null !== $srcObj->enumCaseValue) {
                        $backing->copyFrom($srcObj->enumCaseValue);
                    }
                    $objVar = EnumCaseSupport::createCase(
                        $srcObj->class,
                        $srcObj->enumCaseName ?? '',
                        $backing
                    );
                    $stored->object($objVar->toObject());
                    break;
                }
                $detached = new ObjectEntry($srcObj->class);
                $detached->constructed = $srcObj->constructed;
                $detached->immortalClassConst = true;
                foreach ($srcObj->propertiesWithNames() as $propName => $propVar) {
                    if (!self::isDetachablePropertySlot($propVar)) {
                        continue;
                    }
                    $detached->allocateProperty($propName)->copyFrom(
                        self::detachConstantValue($propVar)
                    );
                }
                SplDualIteratorStorage::transferState($srcObj->id, $detached->id);
                $stored->object($detached);
                break;
            case Variable::TYPE_ENUM_CASE:
                $case = $src->toEnumCase();
                $objVar = EnumCaseSupport::createCase($case->enumClass, $case->caseName, $case->backingValue);
                $stored->object($objVar->toObject());
                break;
            case Variable::TYPE_ARRAY:
                $stored->array($src->toArray());
                break;
            case Variable::TYPE_PROPERTY_HOOK_REF:
                $stored->copyFrom($src);
                break;
            default:
                throw new \LogicException(
                    'Unsupported class constant value type: '.$src->type
                );
        }

        return $stored;
    }

    /** Skip uninitialized typed instance slots (ext/dom prototypes, #17722). */
    private static function isDetachablePropertySlot(Variable $propVar): bool
    {
        if (TypedPropertyCheck::isUninitialized($propVar)) {
            return false;
        }
        $resolved = $propVar->resolveIndirect();
        if (Variable::TYPE_STRING === $resolved->type && null === $resolved->optionalScalarString()) {
            return false;
        }
        if (
            Variable::TYPE_INTEGER === $resolved->type
            && null === $resolved->optionalScalarInt()
            && !$resolved->isStreamResource()
            && !$resolved->isDirResource()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Compile-time (object) array cast for define()/const (#17676, basic_functions.c).
     */
    public static function materializeStdClassFromArrayVariable(Variable $src): ?Variable
    {
        $src = $src->resolveIndirect();
        if ($src->is(Variable::TYPE_OBJECT)) {
            return self::detachConstantValue($src);
        }
        if (!$src->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $classEntry = new ClassEntry('stdClass');
        $object = new ObjectEntry($classEntry);
        $object->constructed = true;
        foreach ($src->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $propName = $keyVar->is(Variable::TYPE_INTEGER)
                ? (string) $keyVar->toInt()
                : $keyVar->toString();
            $object->allocateProperty($propName)->copyFrom(self::detachConstantValue($valueVar));
        }
        $stored = new Variable(Variable::TYPE_OBJECT);
        $stored->object($object);

        return $stored;
    }
}
