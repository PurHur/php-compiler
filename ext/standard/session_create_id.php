<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_create_id() — generate collision-resistant session id (php-src ext/session/session.c; #6002). */
class session_create_id extends Internal
{
    public function __construct()
    {
        parent::__construct('session_create_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('session_create_id() expects at most 1 argument, '.$argc.' given');
        }
        $prefix = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            $prefix = VmString::coerceNullableStringBuiltinArg($arg, 'session_create_id', 0, 'prefix');
        }
        $result = VmSession::createId($prefix);
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
            throw new \LogicException('session_create_id() accepts at most one argument in this compiler build');
        }

        return JitSessionCreateId::invoke($context, ...$args);
    }
}
