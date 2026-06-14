<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** phpinfo() — runtime configuration report (ext/standard/info.c parity, #3359, #5304). */
final class phpinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('phpinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('phpinfo() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $flags = VmInfo::INFO_ALL;
        if (1 === $argc) {
            $flags = VmInfo::resolvePhpinfoFlagsArg($frame->calledArgs[0]);
        }
        $frame->returnVar->bool(VmInfo::phpinfo($flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('phpinfo() is VM-only in this compiler build (issue #5304)');
    }
}
