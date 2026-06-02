<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * set_exception_handler() — user uncaught-exception callbacks (issue #3146).
 */
final class set_exception_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('set_exception_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 1) {
            throw new \LogicException('set_exception_handler() expects exactly 1 argument');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $result = VmExceptionHandler::set($frame->vmContext, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'set_exception_handler() is VM-only in this compiler build (issue #3146)'
        );
    }
}
