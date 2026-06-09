<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_unset() — clear session variables (php-src ext/session/session.c; pairs #6261). */
class session_unset extends Internal
{
    public function __construct()
    {
        parent::__construct('session_unset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError('session_unset() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $frame->returnVar->bool(VmSession::unsetVariables($ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_unset() expects exactly 0 arguments in this compiler build');
        }

        throw new \LogicException('session_unset() not implemented for JIT');
    }
}
