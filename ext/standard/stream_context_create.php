<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_context_create() — VM returns array stream-context representation (#1377).
 *
 * JIT: {@see JitStreamContextCreate} (string-key options arrays; #1377, #2457).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/streamsfuncs.c).
 */
final class stream_context_create extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_create');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..2 — #30584.
        $this->requireArgCountRange($frame, 'stream_context_create', 0, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }

        $optionsVar = $argc >= 1 ? $frame->calledArgs[0] : null;
        $paramsVar = 2 === $argc ? $frame->calledArgs[1] : null;

        $frame->returnVar->array(VmStreamContext::createFromVmOptions($optionsVar, $paramsVar));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireArgCountRangeJit($context, $args, 'stream_context_create', 0, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitStreamContextCreate::invoke($context, ...$args);
    }
}
