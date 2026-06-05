<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;

/**
 * Class/enum type parameter checks at JIT/AOT call sites (#6145, zend_type_hold.c).
 */
final class ClassParamCheck
{
    public static function enforce(
        Context $context,
        Variable $arg,
        string $className,
        string $kind = 'Argument'
    ): void {
        $expected = ltrim($className, '\\');
        $classLc = strtolower($expected);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $scalarGiven = self::scalarGivenLabel($arg);
        if (null !== $scalarGiven) {
            self::raiseTypeErrorAndAbort(
                $context,
                sprintf('%s must be of type %s, %s given', $kind, $expected, $scalarGiven)
            );

            return;
        }
        $ok = $objectType->emitInstanceOf($arg, $classLc);
        self::branchOnBoolOrRaise($context, $ok, $kind, $expected, $arg, $objectType);
    }

    private static function branchOnBoolOrRaise(
        Context $context,
        Variable $ok,
        string $kind,
        string $expected,
        Variable $arg,
        ObjectType $objectType
    ): void {
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $pass = $fn->appendBasicBlock('class_param_ok');
        $fail = $fn->appendBasicBlock('class_param_fail');
        $resume = $fn->appendBasicBlock('class_param_resume');
        $bool = $context->helper->loadValue($ok);
        $context->builder->branchIf($bool, $pass, $fail);
        $context->builder->positionAtEnd($fail);
        $scalarGiven = self::scalarGivenLabel($arg);
        if (null !== $scalarGiven) {
            self::raiseTypeErrorAndAbort(
                $context,
                sprintf('%s must be of type %s, %s given', $kind, $expected, $scalarGiven)
            );
        } else {
            self::emitObjectFailureMessage($context, $objectType, $arg, $kind, $expected);
        }
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    private static function raiseTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitObjectFailureMessage(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $kind,
        string $expected
    ): void {
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $defaultBlock = $fn->appendBasicBlock('class_param_fail_default');
        $checkBlock = $entry;
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $given = strtolower(ltrim($name, '\\'));
            $message = sprintf('%s must be of type %s, %s given', $kind, $expected, $given);
            $matchBlock = $fn->appendBasicBlock('class_param_fail_msg_'.$id);
            $nextBlock = $fn->appendBasicBlock('class_param_fail_try_'.($id + 1));
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            self::raiseTypeErrorAndAbort($context, $message);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            sprintf('%s must be of type %s, object given', $kind, $expected)
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
}
