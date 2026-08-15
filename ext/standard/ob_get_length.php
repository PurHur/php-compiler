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
 * ob_get_length() — byte length of active output buffer (ext/standard/output.c, issue #3236; JIT {@see JitObGetLength}).
 *
 * Excess argc → Zend ArgumentCountError (#30683; php-src ext/standard/output.c).
 */
final class ob_get_length extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_length');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30683; ext/standard/output.c).
        $this->requireExactArgCount($frame, 'ob_get_length', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $length = OutputBuffer::getLength();
        if (null === $length) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($length);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30683 / peer #30628).
        if (!$this->requireExactJitArgCount($context, $args, 'ob_get_length', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitObGetLength::invoke($context, ...$args);
    }
}
