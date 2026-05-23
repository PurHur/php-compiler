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

/** rmdir() — VM via VmFs; JIT/AOT via libc rmdir(2). */
final class rmdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rmdir');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rmdir() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('rmdir() requires a string path in this compiler build');
        }
        $frame->returnVar->bool(VmFs::rmdir($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rmdir() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'rmdir() path');

        return JitRmdir::invoke($context, $path);
    }
}
