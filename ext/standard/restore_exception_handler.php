<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * restore_exception_handler() — pop handler stack (issue #3146).
 */
final class restore_exception_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('restore_exception_handler');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('restore_exception_handler() takes no arguments');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $restored = VmExceptionHandler::restore($frame->vmContext);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($restored);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'restore_exception_handler() is VM-only in this compiler build (issue #3146)'
        );
    }
}
