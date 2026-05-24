<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ErrorHandlerCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * set_error_handler() — VM string user-function callbacks (issue #1379).
 */
final class set_error_handler_ extends Internal
{
    public function __construct()
    {
        parent::__construct('set_error_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('set_error_handler() requires one or two arguments');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $callback = $frame->calledArgs[0]->resolveIndirect();
        if (!ErrorHandlerCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ErrorHandlerCallbackPolicy::vmRejectionMessage());
        }
        $maskVar = 2 === $argc ? $frame->calledArgs[1] : null;
        $result = VmErrorHandler::set($frame->vmContext, $frame->calledArgs[0], $maskVar);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException(
            'set_error_handler() is not implemented for JIT in this compiler build (#1379)'
        );
    }
}
