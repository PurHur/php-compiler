<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Minimal Throwable / Error / TypeError VM registration for runtime TypeError dispatch (#3445, #3371).
 *
 * php-src: Zend/zend_exceptions.c
 */
final class BuiltinExceptionSupport
{
    public const CLASS_ERROR = 'error';
    public const CLASS_TYPE_ERROR = 'typeerror';
    public const CLASS_DIVISION_BY_ZERO_ERROR = 'divisionbyzeroerror';
    public const CLASS_THROWABLE = 'throwable';
    public const PROP_MESSAGE = 'message';

    public static function materializeTypeError(Context $ctx, string $message): Variable
    {
        return self::materializeThrowable($ctx, self::CLASS_TYPE_ERROR, $message);
    }

    public static function materializeError(Context $ctx, string $message): Variable
    {
        return self::materializeThrowable($ctx, self::CLASS_ERROR, $message);
    }

    private static function materializeThrowable(Context $ctx, string $classLc, string $message): Variable
    {
        if (!isset($ctx->classes[$classLc])) {
            throw new \LogicException("{$classLc} builtin class is not registered");
        }
        $entry = $ctx->classes[$classLc];
        $obj = new ObjectEntry($entry);
        $obj->getProperty(self::PROP_MESSAGE)->string($message);
        $obj->constructed = true;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function materializeDivisionByZeroError(Context $ctx, string $message): Variable
    {
        if (!isset($ctx->classes[self::CLASS_DIVISION_BY_ZERO_ERROR])) {
            throw new \LogicException('DivisionByZeroError builtin class is not registered');
        }
        $entry = $ctx->classes[self::CLASS_DIVISION_BY_ZERO_ERROR];
        $obj = new ObjectEntry($entry);
        $obj->getProperty(self::PROP_MESSAGE)->string($message);
        $obj->constructed = true;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }
}
