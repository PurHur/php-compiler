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

/** filesize() — VM via stat; JIT/AOT via libc stat st_size. */
final class filesize extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filesize() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('filesize() requires a string path in this compiler build');
        }
        $size = VmFs::fileSize($v->toString());
        if (false === $size) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($size);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filesize() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'filesize() path');

        return JitFilesize::invoke($context, $path);
    }
}
