<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** session_regenerate_id() — rotate session id (issue #1186). */
final class session_regenerate_id extends Internal
{
    public function __construct()
    {
        parent::__construct('session_regenerate_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('session_regenerate_id() accepts at most one argument in this compiler build');
        }
        $deleteOld = false;
        if (1 === $argc) {
            $flag = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException(
                    'session_regenerate_id() argument must be a boolean in this compiler build'
                );
            }
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
