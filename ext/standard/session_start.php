<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_start() — resume or create file-backed $_SESSION (issues #64, #1182–#1186). */
final class session_start extends Internal
{
    public function __construct()
    {
        parent::__construct('session_start');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('session_start() takes no arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::start($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'session_start() not implemented for JIT in this compiler build (issues #64, #1182–#1186)'
        );
    }
}
