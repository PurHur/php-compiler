<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * set_error_handler() / restore_error_handler() VM state (issue #1379).
 *
 * VM subset: string user-function callbacks only; closures deferred (#142).
 */
final class VmErrorHandler
{
    public static function set(
        Context $context,
        Variable $callback,
        ?Variable $maskVar
    ): Variable {
        $reporter = $context->errors;
        $mask = self::parseMask($maskVar);
        $callbackName = self::callbackName($callback);
        $previous = $reporter->pushHandler($callbackName, $mask);

        return self::handlerReturnValue($previous);
    }

    public static function restore(Context $context): bool
    {
        return $context->errors->popHandler();
    }

    private static function parseMask(?Variable $maskVar): int
    {
        if (null === $maskVar) {
            return \E_ALL;
        }
        $mask = $maskVar->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $mask->type) {
            throw new \LogicException(
                'set_error_handler() error type mask must be an integer in this compiler build'
            );
        }

        return $mask->toInt();
    }

    private static function callbackName(Variable $callback): ?string
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $resolved->type) {
            throw new \LogicException(ErrorHandlerCallbackPolicy::vmRejectionMessage());
        }

        return $resolved->toString();
    }

    public static function handlerReturnValue(?string $name): Variable
    {
        $out = new Variable();
        if (null === $name) {
            $out->null();

            return $out;
        }
        $out->string($name);

        return $out;
    }

    public static function invokeHandler(
        Context $context,
        Frame $frame,
        string $functionName,
        int $errno,
        string $errstr,
        ?string $errfile,
        int $errline
    ): bool {
        $fn = VmUserCall::resolveStringCallback($context, $functionName);
        $errnoVar = new Variable(Variable::TYPE_INTEGER);
        $errnoVar->int($errno);
        $errstrVar = new Variable(Variable::TYPE_STRING);
        $errstrVar->string($errstr);
        $fileVar = new Variable();
        if (null === $errfile) {
            $fileVar->null();
        } else {
            $fileVar->string($errfile);
        }
        $lineVar = new Variable(Variable::TYPE_INTEGER);
        $lineVar->int($errline);
        $result = $context->runtime->vm->invokePhpFunction(
            $fn,
            $errnoVar,
            $errstrVar,
            $fileVar,
            $lineVar
        );
        $resolved = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return 0 !== $resolved->toInt();
        }

        return false;
    }
}
