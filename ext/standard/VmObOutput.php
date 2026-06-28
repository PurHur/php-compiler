<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Output-buffer handler dispatch for ob_start() callbacks (ext/standard/output.c, issue #4655).
 */
final class VmObOutput
{
    public static function processHandler(Context $ctx, string $handlerName, string $content): string
    {
        if ('ob_gzhandler' === $handlerName) {
            return VmObGzhandler::flushBuffer($content, $ctx);
        }
        if (VmUrlRewriterOb::HANDLER_NAME === $handlerName) {
            return VmUrlRewriterOb::applyHandler($content);
        }

        $callback = new Variable();
        $callback->string($handlerName);
        $data = new Variable();
        $data->string($content);
        $mode = new Variable();
        $mode->int(\PHP_OUTPUT_HANDLER_END);
        $result = VmCallable::invoke($ctx, $callback, $data, $mode);

        return $result->toString();
    }

    public static function resolveHandlerName(Frame $frame): ?string
    {
        if ([] === $frame->calledArgs) {
            return null;
        }
        $callback = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \TypeError('ob_start(): Argument #1 ($callback) must be a valid callback');
        }
        $name = $callback->toString();
        $ctx = VmReflection::requireContext($frame);
        if (!VmCallable::isCallable($ctx, $callback)) {
            throw new \TypeError(sprintf(
                'ob_start(): Argument #1 ($callback) must be a valid callback, function "%s" not found or invalid function name',
                $name
            ));
        }

        return $name;
    }
}
