<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * ob_clean() — discard active buffer contents without ending level (ext/standard/output.c, #3588; JIT {@see JitObClean}).
 *
 * Excess argc → Zend ArgumentCountError (#30525; php-src ext/standard/output.c).
 */
final class ob_clean extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_clean');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30525).
        $this->requireExactArgCount($frame, 'ob_clean', 0);
        if (null === $frame->returnVar) {
            OutputBuffer::clean();

            return;
        }
        $frame->returnVar->bool(OutputBuffer::clean());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30525 / peer #30508).
        if (!$this->requireExactJitArgCount($context, $args, 'ob_clean', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitObClean::invoke($context, ...$args);
    }
}
