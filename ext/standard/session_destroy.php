<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_destroy() — destroy session data (issue #1182). */
final class session_destroy extends Internal
{
    public function __construct()
    {
        parent::__construct('session_destroy');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('session_destroy() takes no arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::destroy($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_destroy() takes no arguments in this compiler build');
        }

        return JitSessionDestroy::invoke($context);
    }
}
