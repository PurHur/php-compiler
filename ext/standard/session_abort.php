<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_abort() — discard session changes and end active session (php-src ext/session/session.c; #6002). */
class session_abort extends Internal
{
    public function __construct()
    {
        parent::__construct('session_abort');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError('session_abort() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        $result = VmSession::abort();
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_abort() expects exactly 0 arguments in this compiler build');
        }

        throw new \LogicException('session_abort() not implemented for JIT');
    }
}
