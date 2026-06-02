<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_resource_id() via __compiler_is_resource (#3180). */
final class JitGetResourceId
{
    private const TYPE_ERROR = 'get_resource_id(): Argument #1 ($resource) must be of type resource, %s given';

    public static function invoke(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'null'));

            return $context->constantFromInteger(0, 'int64');
        }

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'get_resource_id() argument #1 ($resource)'),
            $context->getTypeFromString('int64')
        );
        $isRes = JitIsResource::invoke($context, $handle);
        $okBlock = BasicBlockHelper::append($context, 'get_resource_id_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_resource_id_err');
        $context->builder->branchIf($isRes, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, self::jitTypeError($arg->type));

        $context->builder->positionAtEnd($okBlock);

        return $handle;
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeError(int $type): string
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
            case JITVariable::TYPE_OBJECT:
                return \sprintf(self::TYPE_ERROR, 'object');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
    }
}
