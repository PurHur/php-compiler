<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** zend_version() — Zend engine version string (Zend/zend.c parity, #3359, #5304). */
final class zend_version extends Internal
{
    public function __construct()
    {
        parent::__construct('zend_version');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('zend_version() expects exactly 0 arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmInfo::zend_version());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('zend_version() is VM-only in this compiler build (issue #5304)');
    }
}
