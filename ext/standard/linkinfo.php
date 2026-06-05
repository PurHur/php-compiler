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

/** linkinfo() — st_dev from lstat(2) on the link (php-src ext/standard/link.c, #6083). */
final class linkinfo extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('linkinfo() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('linkinfo() requires a string path in this compiler build');
        }
        $dev = VmFs::linkinfo($v->toString());
        if (false === $dev) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($dev);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('linkinfo() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'linkinfo() path');

        return JitLinkinfo::invoke($context, $path);
    }
}
