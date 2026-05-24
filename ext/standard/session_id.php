<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\SessionContext;
use PHPLLVM\Value;

/**
 * session_id() — get/set session id string (VM SessionContext; JIT/AOT __phpc_session_id_apply, issue #1183).
 */
final class session_id extends Internal
{
    public function __construct()
    {
        parent::__construct('session_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('session_id() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            if (1 === $argc) {
                $arg = $frame->calledArgs[0]->resolveIndirect();
                if (Variable::TYPE_STRING === $arg->type) {
                    SessionContext::set($arg->toString());
                } elseif (Variable::TYPE_NULL !== $arg->type) {
                    throw new \LogicException('session_id() id must be a string in this compiler build');
                }
            }

            return;
        }
        if (0 === $argc) {
            $frame->returnVar->string(SessionContext::get());

            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            $frame->returnVar->string(SessionContext::get());

            return;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('session_id() id must be a string in this compiler build');
        }
        $frame->returnVar->string(SessionContext::set($arg->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('session_id() accepts at most one argument');
        }

        return JitSessionId::invoke($context, ...$args);
    }
}
