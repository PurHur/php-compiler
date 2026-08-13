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
 * stream_context_get_params() — read options + params metadata (ext/standard/streams.c, #3448).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/streamsfuncs.c).
 */
final class stream_context_get_params extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_get_params');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30584.
        $this->requireExactArgCount($frame, 'stream_context_get_params', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmStreamContext::getParamsHashTable($frame->calledArgs[0]));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireExactJitArgCount($context, $args, 'stream_context_get_params', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitStreamContextGetParams::invoke($context, ...$args);
    }
}
