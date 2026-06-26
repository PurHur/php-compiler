<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * set_exception_handler() / restore_exception_handler() VM state (issue #3146).
 *
 * @see ext/standard/basic_functions.c — PHP_FUNCTION(set_exception_handler)
 */
final class VmExceptionHandler
{
    public static function set(Context $context, Variable $callback): Variable
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            $removed = $context->exceptionHandlers->popReturningRemoved();

            return self::handlerReturnValue($removed);
        }
        self::assertSupportedCallback($resolved);
        $previous = $context->exceptionHandlers->push($callback);

        return self::handlerReturnValue($previous);
    }

    public static function restore(Context $context): bool
    {
        return $context->exceptionHandlers->pop();
    }

    public static function invoke(
        Context $context,
        Variable $handler,
        Variable $exception,
        ?ClosureState $pinnedClosure = null
    ): bool {
        $handler = $handler->resolveIndirect();
        $exceptionArg = new Variable();
        $exceptionArg->copyFrom($exception);

        if (null !== $pinnedClosure) {
            $result = VmClosureCall::invoke($context, $pinnedClosure, $exceptionArg);
        } elseif (VmClosureCall::isClosure($handler)) {
            $result = VmClosureCall::invoke(
                $context,
                VmClosureCall::resolve($handler),
                $exceptionArg
            );
        } elseif (Variable::TYPE_STRING === $handler->type) {
            $fn = self::resolveStringCallback($context, $handler->toString());
            $result = $context->runtime->vm->invokePhpFunction($fn, $exceptionArg);
        } else {
            throw new \LogicException(
                'set_exception_handler() callback must be null, a closure, or a string function name in this compiler build'
            );
        }

        $resolved = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type && !$resolved->toBool()) {
            return false;
        }

        return true;
    }

    public static function handlerReturnValue(?Variable $handler): Variable
    {
        $out = new Variable();
        if (null === $handler) {
            $out->null();

            return $out;
        }
        $out->copyFrom($handler);

        return $out;
    }

    private static function assertSupportedCallback(Variable $callback): void
    {
        if (EnumCaseSupport::isEnumCaseVariable($callback)) {
            throw new \TypeError(ExceptionHandlerCallbackPolicy::invalidCallbackTypeError());
        }
        if (VmClosureCall::isClosure($callback)) {
            return;
        }
        if (Variable::TYPE_STRING === $callback->type) {
            return;
        }

        throw new \LogicException(
            'set_exception_handler() callback must be null, a closure, or a string function name in this compiler build'
        );
    }

    private static function resolveStringCallback(Context $context, string $name): \PHPCompiler\Func\PHP
    {
        $lc = strtolower($name);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException(
                "set_exception_handler() callback '{$name}' is not a defined function in this compiler build"
            );
        }
        $fn = $context->functions[$lc];
        if (!$fn instanceof \PHPCompiler\Func\PHP) {
            throw new \LogicException(
                "set_exception_handler() callback '{$name}' must be a user-defined function in this compiler build"
            );
        }

        return $fn;
    }
}
