<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getmygrgid() — real group id (ext/standard/basic_functions.c, #3611). */
final class getmygrgid extends Internal
{
    public function __construct()
    {
        parent::__construct('getmygrgid');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('getmygrgid() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDate::getmygrgid());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('getmygrgid() takes no arguments');
        }

        return JitDate::getmygrgid($context);
    }
}
