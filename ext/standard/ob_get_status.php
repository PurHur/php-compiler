<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ob_get_status() — output buffer metadata (ext/standard/output.c, issue #3647; JIT {@see JitObGetStatus}).
 */
final class ob_get_status extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_status');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'ob_get_status() expects at most 1 argument, '.$argc.' given'
            );
        }
        $full = false;
        if ($argc > 0) {
            $full = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                0,
                'ob_get_status',
                1,
                'full_status'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOb::getStatus($full));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObGetStatus::invoke($context, ...$args);
    }
}
