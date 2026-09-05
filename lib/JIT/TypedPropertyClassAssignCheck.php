<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\DnfType;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Builder;

/**
 * AOT: class-typed instance property writes (#31835, zend_object_handlers.c).
 *
 * Nullable `?Class` / `Class|null` must accept null (php-src zend_check_property_type).
 * Typed `?I $v = null` params often lower as TYPE_OBJECT with a null pointer — never
 * load class_id before a null check (#36382 Slim CallableResolver::$container).
 *
 * Builtin `object` / `?object` is not a class name: any non-null object passes
 * (zend_verify_property_type IS_OBJECT). Calling emitInstanceOf(..., "object")
 * rejected every assign (`Cannot assign A to property H::$o of type object`) and
 * blocked Slim AppFactory::create (`public object $proxy`, #36382).
 */
final class TypedPropertyClassAssignCheck
{
    public static function enforce(
        Context $context,
        Variable $value,
        string $resolvedClass,
        string $declaringClass,
        string $propertyName,
        string $declaredTypeLabel,
        bool $allowsNull = false
    ): void {
        $resolvedClass = ltrim($resolvedClass, '\\');
        $declaringClass = ltrim($declaringClass, '\\');
        $expectedLabel = DnfType::zendTypeErrorLabel($declaredTypeLabel);
        $allowsNull = $allowsNull || self::labelAllowsNull($declaredTypeLabel);
        ExceptionBridge::ensureLinked($context);
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);

        if (self::isCompileTimeNull($value)) {
            if ($allowsNull) {
                return;
            }
            self::raise(
                $context,
                sprintf(
                    'Cannot assign null to property %s::$%s of type %s',
                    $declaringClass,
                    $propertyName,
                    $expectedLabel
                )
            );

            return;
        }

        // Runtime null in a TYPE_VALUE / TYPE_OBJECT slot (typed `?T $v = null` params).
        if (Variable::TYPE_VALUE === $value->type || Variable::TYPE_OBJECT === $value->type) {
            self::enforceMaybeNullObject(
                $context,
                $objectType,
                $value,
                $resolvedClass,
                $declaringClass,
                $propertyName,
                $expectedLabel,
                $allowsNull
            );

            return;
        }

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

        // Builtin `object`: any object value (no class-id match).
        if (self::isBuiltinObjectConstraint($resolvedClass)) {
            return;
        }

        $ok = $objectType->emitInstanceOf($value, strtolower($resolvedClass));
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
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

    private static function enforceMaybeNullObject(
        Context $context,
        ObjectType $objectType,
        Variable $value,
        string $resolvedClass,
        string $declaringClass,
        string $propertyName,
        string $expectedLabel,
        bool $allowsNull
    ): void {
        $isNull = self::emitIsNullObjectish($context, $value);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $nullBlock = $fn->appendBasicBlock('typed_prop_class_null');
        $objBlock = $fn->appendBasicBlock('typed_prop_class_obj');
        $resume = $fn->appendBasicBlock('typed_prop_class_resume');
        $context->builder->branchIf($context->helper->loadValue($isNull), $nullBlock, $objBlock);

        $context->builder->positionAtEnd($nullBlock);
        if ($allowsNull) {
            $context->builder->branch($resume);
        } else {
            self::raise(
                $context,
                sprintf(
                    'Cannot assign null to property %s::$%s of type %s',
                    $declaringClass,
                    $propertyName,
                    $expectedLabel
                )
            );
        }

        $context->builder->positionAtEnd($objBlock);
        // Builtin `object` / `?object`: any non-null object (zend IS_OBJECT), not instanceof
        // a class literally named "object" (#36382 AppFactory `public object $proxy`).
        if (self::isBuiltinObjectConstraint($resolvedClass)) {
            self::enforceBuiltinObjectPayload(
                $context,
                $value,
                $declaringClass,
                $propertyName,
                $expectedLabel,
                $resume
            );

            return;
        }
        $ok = $objectType->emitInstanceOf($value, strtolower($resolvedClass));
        $pass = $fn->appendBasicBlock('typed_prop_class_ok');
        $fail = $fn->appendBasicBlock('typed_prop_class_fail');
        $bool = $context->helper->loadValue($ok);
        $context->builder->branchIf($bool, $pass, $fail);
        $context->builder->positionAtEnd($fail);
        self::emitObjectFailureMessage($context, $objectType, $value, $declaringClass, $propertyName, $expectedLabel);
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    /**
     * After null rejection: TYPE_OBJECT is already an object; TYPE_VALUE must be IS_OBJECT.
     *
     * @param \PHPLLVM\Value\BasicBlock $resume
     */
    private static function enforceBuiltinObjectPayload(
        Context $context,
        Variable $value,
        string $declaringClass,
        string $propertyName,
        string $expectedLabel,
        $resume
    ): void {
        if (Variable::TYPE_OBJECT === $value->type) {
            $context->builder->branch($resume);
            $context->builder->positionAtEnd($resume);

            return;
        }
        if (Variable::TYPE_VALUE !== $value->type) {
            $context->builder->branch($resume);
            $context->builder->positionAtEnd($resume);

            return;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $pass = $fn->appendBasicBlock('typed_prop_builtin_obj_ok');
        $fail = $fn->appendBasicBlock('typed_prop_builtin_obj_fail');
        $context->builder->branchIf($isObj, $pass, $fail);
        $context->builder->positionAtEnd($fail);
        self::raise(
            $context,
            sprintf(
                'Cannot assign %s to property %s::$%s of type %s',
                'non-object',
                $declaringClass,
                $propertyName,
                $expectedLabel
            )
        );
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    /** Declared type `object` / `?object` — not a user class named "object". */
    private static function isBuiltinObjectConstraint(string $resolvedClass): bool
    {
        return 0 === strcasecmp(ltrim($resolvedClass, '\\'), 'object');
    }

    /** True when TYPE_OBJECT pointer is null, or TYPE_VALUE is null / object-tagged null. */
    private static function emitIsNullObjectish(Context $context, Variable $arg): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        if (Variable::TYPE_OBJECT === $arg->type) {
            $obj = $context->helper->loadValue($arg);
            $objType = $context->getTypeFromString('__object__*');
            $isNull = $context->builder->icmp(
                Builder::INT_EQ,
                $obj,
                $objType->constNull()
            );

            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $isNull);
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNullKind = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isObjKind = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $ptrNull = $context->builder->icmp(
            Builder::INT_EQ,
            $obj,
            $objType->constNull()
        );

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $context->builder->or($isNullKind, $context->builder->and($isObjKind, $ptrNull))
        );
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

    private static function isCompileTimeNull(Variable $arg): bool
    {
        if (Variable::TYPE_NULL === $arg->type) {
            return true;
        }

        return Variable::TYPE_VALUE === $arg->type && ($arg->isNullConstant ?? false);
    }

    /** Nullable declared types: `?T`, `T|null`, `mixed` (zend_check_property_type). */
    private static function labelAllowsNull(string $label): bool
    {
        $label = trim($label);
        if ('' === $label || 'mixed' === $label || 'null' === $label) {
            return true;
        }
        if (str_starts_with($label, '?')) {
            return true;
        }
        foreach (explode('|', $label) as $arm) {
            if ('null' === strtolower(trim($arm))) {
                return true;
            }
        }

        return false;
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
