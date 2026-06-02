<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getmyinode() — inode of the executed script (ext/standard/basic_functions.c, #3611). */
final class getmyinode extends Internal
{
    public function __construct()
    {
        parent::__construct('getmyinode');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('getmyinode() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $inode = VmDate::getmyinode($frame);
        if (false === $inode) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($inode);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('getmyinode() takes no arguments');
        }

        return JitDate::getmyinode($context);
    }
}
