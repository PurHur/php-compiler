<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\DnfType;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Builder;

/**
 * AOT: class-typed instance property writes (#31835, zend_object_handlers.c).
 */
final class TypedPropertyClassAssignCheck
{
    public static function enforce(
        Context $context,
        Variable $value,
        string $resolvedClass,
        string $declaringClass,
        string $propertyName,
        string $declaredTypeLabel
    ): void {
        $resolvedClass = ltrim($resolvedClass, '\\');
        $declaringClass = ltrim($declaringClass, '\\');
        $expectedLabel = DnfType::zendTypeErrorLabel($declaredTypeLabel);
        ExceptionBridge::ensureLinked($context);
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $scalarGiven = self::scalarGivenLabel($value);
        if (null !== $scalarGiven) {
            self::raise(
                $context,
                sprintf(
                    'Cannot assign %s to property %s::$%s of type %s',
                    $scalarGiven,
                    $declaringClass,
                    $propertyName,
                    $expectedLabel
                )
            );

            return;
        }
        $ok = $objectType->emitInstanceOf($value, strtolower($resolvedClass));
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $pass = $fn->appendBasicBlock('typed_prop_class_ok');
        $fail = $fn->appendBasicBlock('typed_prop_class_fail');
        $resume = $fn->appendBasicBlock('typed_prop_class_resume');
        $bool = $context->helper->loadValue($ok);
        $context->builder->branchIf($bool, $pass, $fail);
        $context->builder->positionAtEnd($fail);
        self::emitObjectFailureMessage($context, $objectType, $value, $declaringClass, $propertyName, $expectedLabel);
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    private static function emitObjectFailureMessage(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $declaringClass,
        string $propertyName,
        string $expectedLabel
    ): void {
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $defaultBlock = $fn->appendBasicBlock('typed_prop_class_fail_default');
        $checkBlock = $entry;
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $given = ltrim($name, '\\');
            $message = sprintf(
                'Cannot assign %s to property %s::$%s of type %s',
                $given,
                $declaringClass,
                $propertyName,
                $expectedLabel
            );
            $matchBlock = $fn->appendBasicBlock('typed_prop_class_fail_msg_'.$id);
            $nextBlock = $fn->appendBasicBlock('typed_prop_class_fail_try_'.($id + 1));
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            self::raise($context, $message);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        self::raise(
            $context,
            sprintf(
                'Cannot assign object to property %s::$%s of type %s',
                $declaringClass,
                $propertyName,
                $expectedLabel
            )
        );
    }

    private static function scalarGivenLabel(Variable $arg): ?string
    {
        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function objectPointer(Context $context, Variable $arg): \PHPLLVM\Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }

        return $context->getTypeFromString('__object__*')->constNull();
    }

    private static function raise(Context $context, string $message): void
    {
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
    }
}
