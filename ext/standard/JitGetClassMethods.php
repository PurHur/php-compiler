<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGetClassMethods;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for get_class_methods() via GetClassMethodsJitHelper PHP (#3118, #16729, #23530).
 *
 * Always routes through PHP SSOT so zend_get_executed_scope() visibility is honored
 * (compile-time public-only registry would mis-list private/protected from class scope).
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_methods)
 */
final class JitGetClassMethods
{
    // php-src zend_builtin_functions.c — same wording as VM requireObjectOrValidClassName (#27706)
    private const TYPE_ERROR =
        'get_class_methods(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given';

    public static function invoke(Context $context, JITVariable $classArg): Value
    {
        if (JITVariable::TYPE_OBJECT === $classArg->type
            || JITVariable::TYPE_STRING === $classArg->type
            || JITVariable::TYPE_VALUE === $classArg->type
            || null !== ($classArg->compileTimeEnumCase ?? null)
            || null !== \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($classArg)
        ) {
            return self::routeThroughPhpHelper($context, $classArg);
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($classArg->type));

        return self::returnFalse($context);
    }

    private static function routeThroughPhpHelper(Context $context, JITVariable $classArg): Value
    {
        return StringGetClassMethods::invoke($context, self::operandToValueBox($context, $classArg));
    }

    private static function operandToValueBox(Context $context, JITVariable $classArg): Value
    {
        if (JITVariable::TYPE_VALUE === $classArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $classArg);
        }
        if (JITVariable::TYPE_OBJECT === $classArg->type) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($classArg)
            );

            return $ptr;
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->helper->loadValue($classArg)
        );

        return $ptr;
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
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
    }

    private static function returnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }
}
