<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_write_close() / session_commit() alias — persist $_SESSION and close (issue #1185, #12544). */
class session_write_close extends Internal
{
    public function __construct(string $name = 'session_write_close')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'session_write_close() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::writeClose($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_write_close() takes no arguments in this compiler build');
        }

        return JitSessionWriteClose::invoke($context);
    }
}
