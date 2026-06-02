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
 * ob_end_clean() — discard active buffer and pop level (ext/standard/output.c, issue #3236).
 */
final class ob_end_clean extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_end_clean');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_end_clean() takes no arguments');
        }
        if (null === $frame->returnVar) {
            OutputBuffer::endClean();

            return;
        }
        $frame->returnVar->bool(OutputBuffer::endClean());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_end_clean() is VM-only in this compiler build');
    }
}
