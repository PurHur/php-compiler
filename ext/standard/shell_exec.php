<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** shell_exec() — run command via host shell (VM; JIT/AOT via __compiler_shell_exec). */
final class shell_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('shell_exec');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('shell_exec() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('shell_exec() requires a string command in this compiler build');
        }
        $result = \shell_exec($v->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } elseif (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('shell_exec() requires exactly one argument');
        }

        return JitShellExec::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'shell_exec() command')
        );
    }
}
