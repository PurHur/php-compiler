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

/** lstat() — symlink-aware metadata array via host lstat(2) (issue #1198). */
final class lstat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('lstat');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('lstat() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('lstat() requires a string path in this compiler build');
        }
        $info = VmFs::statInfo($v->toString(), true);
        if (false === $info) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('lstat() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'lstat() path');

        return JitStatArray::invoke($context, $path, true);
    }
}
