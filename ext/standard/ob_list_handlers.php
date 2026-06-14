<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ob_list_handlers() — list output handler names per buffer level (ext/standard/output.c, #3588; JIT {@see JitObListHandlers}).
 */
final class ob_list_handlers extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_list_handlers');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_list_handlers() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOb::listHandlers());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObListHandlers::invoke($context, ...$args);
    }
}
