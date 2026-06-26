<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\ErrorHandlerCallbackPolicy;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
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
        self::assertValidCallback($context, $callback);
        $storedCallback = self::normalizeCallbackForStorage($callback);
        $previous = $reporter->pushHandler($storedCallback, $mask);

        return self::handlerReturnValue($previous);
    }

    private static function assertValidCallback(Context $context, Variable $callback): void
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return;
        }
        if (VmClosureCall::isClosure($resolved)) {
            return;
        }
        if (!VmCallable::isCallable($context, $callback)) {
            throw new \TypeError(ErrorHandlerCallbackPolicy::invalidCallbackTypeError());
        }
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

    private static function normalizeCallbackForStorage(Variable $callback): Variable
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            $out = new Variable();
            $out->null();

            return $out;
        }
        if (EnumCaseSupport::isEnumCaseVariable($callback)) {
            throw new \TypeError(ErrorHandlerCallbackPolicy::invalidCallbackTypeError());
        }
        if (VmClosureCall::isClosure($resolved)) {
            $out = new Variable();
            $out->copyFrom($resolved);

            return $out;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $out = new Variable();
            $out->string($resolved->toString());

            return $out;
        }
        if (Variable::TYPE_ARRAY === $resolved->type) {
            $out = new Variable();
            $out->copyFrom($resolved);

            return $out;
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            $out = new Variable();
            $out->copyFrom($resolved);

            return $out;
        }

        throw new \LogicException(
            'set_error_handler() callback must be null, a closure, a string function name, an array callable, or an invokable object in this compiler build'
        );
    }

    public static function handlerReturnValue(?Variable $previous): Variable
    {
        $out = new Variable();
        if (null === $previous) {
            $out->null();

            return $out;
        }
        $out->copyFrom($previous);

        return $out;
    }

    public static function invokeHandler(
        Context $context,
        Frame $frame,
        Variable $callback,
        int $errno,
        string $errstr,
        ?string $errfile,
        int $errline
    ): bool {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            $closure = VmClosureCall::resolve($callback);
            $result = VmClosureCall::invoke(
                $context,
                $closure,
                self::errnoVar($errno),
                self::errstrVar($errstr),
                self::fileVar($errfile),
                self::lineVar($errline)
            );

            return self::truthyHandlerResult($result);
        }
        if (Variable::TYPE_STRING === $callback->type) {
            $fn = VmUserCall::resolveStringCallback($context, $callback->toString());
            $result = $context->runtime->vm->invokePhpFunction(
                $fn,
                self::errnoVar($errno),
                self::errstrVar($errstr),
                self::fileVar($errfile),
                self::lineVar($errline)
            );

            return self::truthyHandlerResult($result);
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            $table = $callback->toArray();
            $idx0 = new Variable(Variable::TYPE_INTEGER);
            $idx0->int(0);
            $idx1 = new Variable(Variable::TYPE_INTEGER);
            $idx1->int(1);
            if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
                throw new \LogicException('Invalid array callable');
            }
            $receiver = $table->findVariable($idx0, false)->resolveIndirect();
            $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
            if (Variable::TYPE_OBJECT !== $receiver->type) {
                throw new \LogicException('Invalid array callable');
            }
            $result = $context->runtime->vm->invokeInstanceMethod(
                $receiver->toObject(),
                $methodName,
                self::errnoVar($errno),
                self::errstrVar($errstr),
                self::fileVar($errfile),
                self::lineVar($errline)
            );

            return self::truthyHandlerResult($result);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            $result = $context->runtime->vm->invokeInstanceMethod(
                $callback->toObject(),
                '__invoke',
                self::errnoVar($errno),
                self::errstrVar($errstr),
                self::fileVar($errfile),
                self::lineVar($errline)
            );

            return self::truthyHandlerResult($result);
        }

        throw new \LogicException(
            'set_error_handler() callback must be null, a closure, a string function name, an array callable, or an invokable object in this compiler build'
        );
    }

    private static function errnoVar(int $errno): Variable
    {
        $errnoVar = new Variable(Variable::TYPE_INTEGER);
        $errnoVar->int($errno);

        return $errnoVar;
    }

    private static function errstrVar(string $errstr): Variable
    {
        $errstrVar = new Variable(Variable::TYPE_STRING);
        $errstrVar->string($errstr);

        return $errstrVar;
    }

    private static function fileVar(?string $errfile): Variable
    {
        $fileVar = new Variable();
        if (null === $errfile) {
            $fileVar->null();
        } else {
            $fileVar->string($errfile);
        }

        return $fileVar;
    }

    private static function lineVar(int $errline): Variable
    {
        $lineVar = new Variable(Variable::TYPE_INTEGER);
        $lineVar->int($errline);

        return $lineVar;
    }

    private static function truthyHandlerResult(Variable $result): bool
    {
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
