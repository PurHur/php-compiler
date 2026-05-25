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
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_start() callback arguments not supported in this compiler build');
        }
        OutputBuffer::start();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_start() is not implemented for JIT in this compiler build (#118)');
    }
}
