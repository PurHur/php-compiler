<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * ob_start() — begin output buffering (VM; JIT scaffold {@see JitObStart}, #118, #1056).
 */
final class ob_start extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_start');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 3) {
            throw new \LogicException('ob_start() accepts at most three arguments in this compiler build');
        }
        $handler = null;
        if ($argc >= 1) {
            $handler = VmObOutput::resolveHandler($frame);
        }
        OutputBuffer::start($handler);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObStart::invoke($context, ...$args);
    }
}
