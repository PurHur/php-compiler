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

/** fileatime() — VM via stat; JIT/AOT via libc stat st_atim (php-src ext/standard/filestat.c). */
final class fileatime extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fileatime() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('fileatime() requires a string path in this compiler build');
        }
        $atime = VmFs::fileAtime($v->toString());
        if (false === $atime) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($atime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileatime() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'fileatime() path');

        return JitFileatime::invoke($context, $path);
    }
}
