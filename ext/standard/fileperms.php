<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fileperms() — VM via stat; JIT/AOT via libc stat st_mode. */
final class fileperms extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fileperms() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('fileperms() requires a string path in this compiler build');
        }
        $perms = VmFs::filePerms($v->toString());
        if (false === $perms) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($perms);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileperms() requires exactly one argument in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('fileperms() requires a string path in this compiler build');
        }

        return JitFileperms::invoke($context, $context->helper->loadValue($args[0]));
    }
}
