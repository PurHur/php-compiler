<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_class() — class name or TypeError (#1217, #5456, #4092). */
final class JitGetClass
{
    private const TYPE_ERROR = 'get_class(): Argument #1 ($object) must be of type object, %s given';

    private const NO_THIS_ERROR = 'get_class() without arguments must be called from within a class';

    public static function invokeNoArg(Context $context): Value
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block || null === $block->func || null === $block->func->class) {
            self::emitNoThisErrorAndAbort($context);

            return self::emptyStringBox($context);
        }

        return self::boxString(
            $context,
            $context->builder->load($context->constantStringFromString($block->func->class->value))
        );
    }

    public static function invoke(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return ReflectionBuiltinHelper::getClassName($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::boxed($context, $arg);
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($arg->type));

        return $context->builder->load($context->constantStringFromString(''));
    }

    private static function boxed(Context $context, JITVariable $arg): Value
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'get_class_ok');
        $checkNullBlock = BasicBlockHelper::append($context, 'get_class_check_null');
        $nullErrBlock = BasicBlockHelper::append($context, 'get_class_null_err');
        $mixedErrBlock = BasicBlockHelper::append($context, 'get_class_mixed_err');
        $context->builder->branchIf($isObject, $okBlock, $checkNullBlock);

        $context->builder->positionAtEnd($checkNullBlock);
        $context->builder->branchIf($isNull, $nullErrBlock, $mixedErrBlock);

        $context->builder->positionAtEnd($nullErrBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'null'));

        $context->builder->positionAtEnd($mixedErrBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($okBlock);
        self::emitResourceOperandGuard($context, $arg);

        return ReflectionBuiltinHelper::getClassName($context, $arg);
    }

    private static function emitResourceOperandGuard(Context $context, JITVariable $arg): void
    {
        $resourceClassId = self::resourceClassId($context);
        if (null === $resourceClassId) {
            return;
        }
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $loaded
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $isResource = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->getTypeFromString('int64')->constInt($resourceClassId, false)
        );
        $continueBlock = BasicBlockHelper::append($context, 'get_class_not_resource');
        $resourceErrBlock = BasicBlockHelper::append($context, 'get_class_resource_err');
        $context->builder->branchIf($isResource, $resourceErrBlock, $continueBlock);
        $context->builder->positionAtEnd($resourceErrBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'resource'));
        $context->builder->positionAtEnd($continueBlock);
    }

    private static function resourceClassId(Context $context): ?int
    {
        try {
            return $context->type->object->lookup('Resource');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return \sprintf(self::TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::TYPE_ERROR, 'bool');
            case JITVariable::TYPE_STRING:
                return \sprintf(self::TYPE_ERROR, 'string');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
    }

    private static function emitNoThisErrorAndAbort(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, self::NO_THIS_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function boxString(Context $context, Value $nativeStr): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $nativeStr
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function emptyStringBox(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $context->builder->load($context->constantStringFromString(''))
        );

        return JitValueBox::pointer($context, $slot);
    }
}
