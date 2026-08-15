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
 * ob_list_handlers() — list output handler names per buffer level (ext/standard/output.c, #3588; JIT {@see JitObListHandlers}).
 *
 * Excess argc → Zend ArgumentCountError (#30683; php-src ext/standard/output.c).
 */
final class ob_list_handlers extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_list_handlers');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30683; ext/standard/output.c).
        $this->requireExactArgCount($frame, 'ob_list_handlers', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOb::listHandlers());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30683 / peer #30628).
        if (!$this->requireExactJitArgCount($context, $args, 'ob_list_handlers', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitObListHandlers::invoke($context, ...$args);
    }
}
