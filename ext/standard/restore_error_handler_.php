<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * restore_error_handler() — VM stack pop (issue #1379).
 */
final class restore_error_handler_ extends Internal
{
    public function __construct()
    {
        parent::__construct('restore_error_handler');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('restore_error_handler() takes no arguments');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $restored = VmErrorHandler::restore($frame->vmContext);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($restored);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException(
            'restore_error_handler() is not implemented for JIT in this compiler build (#1379)'
        );
    }
}
