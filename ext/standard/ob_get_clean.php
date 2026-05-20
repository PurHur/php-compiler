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
 * ob_get_clean() — return active buffer and end buffering (VM only; issue #118).
 */
final class ob_get_clean extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_clean');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_get_clean() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === OutputBuffer::getLevel()) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string(OutputBuffer::getClean());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_get_clean() is not implemented for JIT in this compiler build');
    }
}
