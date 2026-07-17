<?php

declare(strict_types=1);

namespace PHPCompiler\ext\reflection;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\IsAnonymousClassRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for isAnonymousClass() (#19969). */
final class JitIsAnonymousClass
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        if (null !== $arg->compileTimeEnumCase) {
            return $context->constantFromBool(false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $obj = $context->helper->loadValue($arg);

            return self::anonymousFromClassId($context, self::loadClassId($context, $obj));
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::boxed($context, $arg);
        }

        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('mixed'));

        return $context->constantFromBool(false);
    }

    private static function boxed(Context $context, JITVariable $arg): Value
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $valMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $valMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isEnum = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ENUM_CASE, false)
        );
        $enumBlock = BasicBlockHelper::append($context, 'is_anon_class_enum');
        $restBlock = BasicBlockHelper::append($context, 'is_anon_class_rest');
        $doneBlock = BasicBlockHelper::append($context, 'is_anon_class_done');
        $context->builder->branchIf($isEnum, $enumBlock, $restBlock);

        $context->builder->positionAtEnd($enumBlock);
        $falseVal = $context->constantFromBool(false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($restBlock);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $objectBlock = BasicBlockHelper::append($context, 'is_anon_class_object');
        $errBlock = BasicBlockHelper::append($context, 'is_anon_class_err');
        $context->builder->branchIf($isObject, $objectBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('mixed'));

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $loaded
        );
        $anonVal = self::anonymousFromClassId($context, self::loadClassId($context, $obj));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($falseVal->getType());
        $phi->addIncoming($falseVal, $enumBlock);
        $phi->addIncoming($anonVal, $objectBlock);

        return $phi;
    }

    private static function loadClassId(Context $context, Value $obj): Value
    {
        $objMap = $context->structFieldMap['__object__'];

        return $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
    }

    private static function anonymousFromClassId(Context $context, Value $classId): Value
    {
        if ($classId->isConstant()) {
            $id = (int) $classId->getZExtValue();
            $names = $context->type->object->registeredClassNamesById();
            $name = $names[$id] ?? '';

            return $context->constantFromBool('' !== $name && str_contains($name, '@anonymous'));
        }

        IsAnonymousClassRuntime::ensureLinked($context);

        return IsAnonymousClassRuntime::invoke($context, $classId);
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function typeErrorMessage(string $given): string
    {
        return \sprintf('isAnonymousClass(): Argument #1 ($object) must be of type object, %s given', $given);
    }
}
