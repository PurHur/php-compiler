<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_destroy() — destroy session data (issue #1182). */
class session_destroy extends Internal
{
    public function __construct()
    {
        parent::__construct('session_destroy');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30676; ext/session/session.c).
        $this->requireExactArgCount($frame, 'session_destroy', 0);
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::destroy($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30676).
        if (!$this->requireExactJitArgCount($context, $args, 'session_destroy', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitSessionDestroy::invoke($context);
    }
}
