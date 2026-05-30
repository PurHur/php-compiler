<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gethostname() — local hostname (VM via host; JIT/AOT via __compiler_gethostname, issue #3465). */
final class gethostname extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostname');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('gethostname() takes no arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $host = VmHost::gethostname();
        if (false === $host) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($host);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('gethostname() takes no arguments in this compiler build');
        }

        return JitGethostname::invoke($context);
    }
}
