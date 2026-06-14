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
 * ob_clean() — discard active buffer contents without ending level (ext/standard/output.c, #3588; JIT {@see JitObClean}).
 */
final class ob_clean extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_clean');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_clean() takes no arguments');
        }
        if (null === $frame->returnVar) {
            OutputBuffer::clean();

            return;
        }
        $frame->returnVar->bool(OutputBuffer::clean());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObClean::invoke($context, ...$args);
    }
}
