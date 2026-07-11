<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ErrorHandlerCallbackPolicy;
use PHPCompiler\JIT\JitOperandTypeLabel;
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
        $this->requireArgCountRange($frame, 'set_error_handler', 1, 2);
        if (null === $frame->vmContext) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $maskVar = 2 === $argc ? $frame->calledArgs[1] : null;
        $result = VmErrorHandler::set($frame->vmContext, $frame->calledArgs[0], $maskVar);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'set_error_handler', 1, 2)) {
            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        $argc = \count($args);
        if (null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $args[0])) {
            throw new \TypeError(ErrorHandlerCallbackPolicy::invalidCallbackTypeError());
        }
        if (!ErrorHandlerCallbackPolicy::isJitLowerable($args[0])) {
            throw new \LogicException(ErrorHandlerCallbackPolicy::jitRejectionMessage());
        }
        $this->jitString($context, $args[0], 'set_error_handler() callback');
        $maskArg = 2 === $argc ? $args[1] : null;
        if (null !== $maskArg && JITVariable::TYPE_NATIVE_LONG !== $maskArg->type) {
            throw new \LogicException('set_error_handler() error type mask must be a compile-time integer');
        }

        return JitErrorHandler::set($context, $args[0], $maskArg);
    }
}
