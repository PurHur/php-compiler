<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSem;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Shared SysvSemaphore object → handle lowering for sem_* JIT (#28431). */
final class JitSemHandle
{
    public static function fromArg(Context $context, JITVariable $arg, string $fn): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, $fn);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $i64 = $context->getTypeFromString('int64');

            return $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        }
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($fn, self::scalarTypeName($arg->type)));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    public static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    public static function typeErrorMessage(string $fn, string $given): string
    {
        return \sprintf('%s(): Argument #1 ($semaphore) must be of type SysvSemaphore, %s given', $fn, $given);
    }

    public static function scalarTypeName(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_NULL:
                return 'null';
            default:
                return 'mixed';
        }
    }

    public static function ensureLinked(Context $context): void
    {
        StringSem::ensureLinked($context);
    }

    public static function emitArgumentCountError(Context $context, string $message): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError($context, $message);
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
