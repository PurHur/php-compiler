<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** session_regenerate_id() — rotate session id (issue #1186). */
class session_regenerate_id extends Internal
{
    public function __construct()
    {
        parent::__construct('session_regenerate_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'session_regenerate_id() expects at most 1 argument, '.$argc.' given'
            );
        }
        $deleteOld = false;
        if (1 === $argc) {
            $flag = InternalStrictArg::requireBool($frame, 0, 'session_regenerate_id', 'delete_old_session');
            $deleteOld = $flag->toBool();
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::regenerateId($ctx, $deleteOld);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('session_regenerate_id() accepts at most one argument in this compiler build');
        }

        return JitSessionRegenerateId::invoke($context, $args[0] ?? null);
    }
}
