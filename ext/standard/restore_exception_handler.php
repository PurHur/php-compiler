<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * restore_exception_handler() — pop handler stack (issue #3146).
 *
 * Excess argc → Zend ArgumentCountError (#30653; php-src ext/standard/basic_functions.c).
 */
final class restore_exception_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('restore_exception_handler');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30653; ext/standard/basic_functions.c).
        $this->requireExactArgCount($frame, 'restore_exception_handler', 0);
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
        // Catchable ArgumentCountError under AOT try/catch (#30653 / peer #30591).
        if (!$this->requireExactJitArgCount($context, $args, 'restore_exception_handler', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitExceptionHandler::restore($context);
    }
}
