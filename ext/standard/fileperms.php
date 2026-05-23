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
        $mode = VmFs::filePerms($v->toString());
        if (false === $mode) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($mode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileperms() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'fileperms() path');

        return JitFileperms::invoke($context, $path);
    }
}
