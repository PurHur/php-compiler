<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Output-buffer handler dispatch for ob_start() callbacks (ext/standard/output.c, issue #4655, #3623).
 */
final class VmObOutput
{
    public static function processHandler(Context $ctx, null|string|Variable|ClosureState $handler, string $content): string
    {
        if (null === $handler) {
            return $content;
        }
        if ($handler instanceof ClosureState) {
            return self::invokeClosureHandler($ctx, $handler, $content);
        }
        if ($handler instanceof Variable) {
            return self::invokeCallableHandler($ctx, $handler, $content);
        }
        if ('ob_gzhandler' === $handler) {
            return VmObGzhandler::flushBuffer($content, $ctx);
        }
        if (VmUrlRewriterOb::HANDLER_NAME === $handler) {
            return VmUrlRewriterFlush::applyHandler($content);
        }

        $callback = new Variable();
        $callback->string($handler);

        return self::invokeCallableHandler($ctx, $callback, $content);
    }

    public static function resolveHandler(Frame $frame): null|string|Variable|ClosureState
    {
        if ([] === $frame->calledArgs) {
            return null;
        }
        $callback = $frame->calledArgs[0]->resolveIndirect();
        // php-src `?callable $callback = null` — null means no user filter (output.c / #30121).
        if (Variable::TYPE_NULL === $callback->type) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($callback)) {
            throw new \TypeError('ob_start(): Argument #1 ($callback) must be a valid callback');
        }
        if (Variable::TYPE_STRING === $callback->type) {
            $name = $callback->toString();
            $ctx = VmReflection::requireContext($frame);
            if (!VmCallable::isCallable($ctx, $frame->calledArgs[0], false, null, $frame)) {
                throw new \TypeError(sprintf(
                    'ob_start(): Argument #1 ($callback) must be a valid callback, function "%s" not found or invalid function name',
                    $name
                ));
            }

            return $name;
        }
        if (VmClosureCall::isClosure($callback)) {
            return VmClosureCall::resolve($callback);
        }
        $ctx = VmReflection::requireContext($frame);
        if (!VmCallable::isCallable($ctx, $frame->calledArgs[0], false, null, $frame)) {
            throw new \TypeError('ob_start(): Argument #1 ($callback) must be a valid callback');
        }
        $copy = new Variable();
        $copy->copyFrom($frame->calledArgs[0]);

        return $copy;
    }

    public static function handlerDisplayName(null|string|Variable|ClosureState $handler, ?Context $ctx = null): string
    {
        if (null === $handler) {
            return VmOb::HANDLER_NAME;
        }
        if ($handler instanceof ClosureState) {
            return 'CLOSURE::__INVOKE';
        }
        if (\is_string($handler)) {
            return $handler;
        }
        $resolved = $handler->resolveIndirect();
        if (VmClosureCall::isClosure($resolved)) {
            return 'CLOSURE::__INVOKE';
        }
        if (null !== $ctx) {
            $name = new Variable();
            if (VmCallable::isCallable($ctx, $handler, false, $name)) {
                return $name->toString();
            }
        }

        return VmOb::HANDLER_NAME;
    }

    private static function invokeClosureHandler(Context $ctx, ClosureState $closure, string $content): string
    {
        $data = new Variable();
        $data->string($content);
        $mode = new Variable();
        $mode->int(\PHP_OUTPUT_HANDLER_END);
        $result = VmClosureCall::invoke($ctx, $closure, $data, $mode);

        return $result->toString();
    }

    private static function invokeCallableHandler(Context $ctx, Variable $callback, string $content): string
    {
        $data = new Variable();
        $data->string($content);
        $mode = new Variable();
        $mode->int(\PHP_OUTPUT_HANDLER_END);
        $resolved = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($resolved)) {
            $result = VmClosureCall::invoke($ctx, VmClosureCall::resolve($resolved), $data, $mode);

            return $result->toString();
        }
        $callable = new Variable();
        $callable->copyFrom($callback);
        $result = VmCallable::invoke($ctx, $callable, $data, $mode);

        return $result->toString();
    }
}
