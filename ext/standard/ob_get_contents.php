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
 * ob_get_contents() — return active buffer without ending (ext/standard/output.c, issue #3236; JIT {@see JitObGetContents}).
 *
 * Excess argc → Zend ArgumentCountError (#30683; php-src ext/standard/output.c).
 */
final class ob_get_contents extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_contents');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30683; ext/standard/output.c).
        $this->requireExactArgCount($frame, 'ob_get_contents', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $contents = OutputBuffer::getContents();
        if (null === $contents) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($contents);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30683 / peer #30628).
        if (!$this->requireExactJitArgCount($context, $args, 'ob_get_contents', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitObGetContents::invoke($context, ...$args);
    }
}
