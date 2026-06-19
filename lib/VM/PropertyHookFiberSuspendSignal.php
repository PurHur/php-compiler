<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/** Resume outer runFrames with FIBER_SUSPEND after hook body Fiber::suspend() (#9862). */
final class PropertyHookFiberSuspendSignal extends \Exception
{
    public function __construct(public readonly Frame $resumeFrame)
    {
        parent::__construct('Property hook fiber suspend');
    }
}
