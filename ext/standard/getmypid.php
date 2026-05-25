<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getmypid() — current process ID (VM host; JIT/AOT via libc getpid, issue #2195). */
final class getmypid extends Internal
{
    public function __construct()
    {
        parent::__construct('getmypid');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('getmypid() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDate::getmypid());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('getmypid() takes no arguments');
        }

        return JitDate::getmypid($context);
    }
}
