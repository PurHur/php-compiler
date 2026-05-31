<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_object_id() via phpc_object_id.c (#3537). */
final class JitGetObjectId
{
    private const TYPE_ERROR = 'get_object_id(): Argument #1 ($object) must be of type object, %s given';

    public static function invoke(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('phpc_get_object_id_from_object'),
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::boxed($context, $arg);
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($arg->type));

        return $context->constantFromInteger(0, 'int64');
    }

    public static function boxed(Context $context, JITVariable $arg): Value
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
        $okBlock = BasicBlockHelper::append($context, 'get_object_id_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_object_id_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($okBlock);

        return $context->builder->call(
            $context->lookupFunction('phpc_get_object_id_from_value'),
            $loaded
        );
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
}
