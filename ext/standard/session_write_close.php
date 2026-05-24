<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_write_close() — persist $_SESSION and close (issue #1185). */
final class session_write_close extends Internal
{
    public function __construct()
    {
        parent::__construct('session_write_close');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('session_write_close() takes no arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::writeClose($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'session_write_close() not implemented for JIT in this compiler build (issue #1185)'
        );
    }
}
