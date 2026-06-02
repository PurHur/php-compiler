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

/** filegroup() — VM via stat; JIT/AOT via libc stat st_gid. php-src: ext/standard/filestat.c */
final class filegroup extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filegroup() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('filegroup() requires a string path in this compiler build');
        }
        $gid = VmFs::fileGroup($v->toString());
        if (false === $gid) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($gid);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filegroup() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'filegroup() path');

        return JitFilegroup::invoke($context, $path);
    }
}
