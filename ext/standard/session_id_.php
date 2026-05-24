<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** session_id() — get or set the active session id (issue #1183). */
final class session_id_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('session_id() accepts at most one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getId());
            }

            return;
        }
        $idVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $idVar->type) {
            throw new \LogicException('session_id() argument must be a string in this compiler build');
        }
        $result = VmSession::setId($idVar->toString());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('session_id() accepts at most one argument in this compiler build');
        }

        return \call_user_func_array([JitSessionId::class, 'invoke'], array_merge([$context], $args));
    }
}
