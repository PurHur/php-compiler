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

/** chdir() — VM via VmFs; JIT/AOT via libc chdir(2). */
final class chdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chdir');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('chdir() requires exactly one argument in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('chdir() directory must be a string in this compiler build');
        }
        $frame->returnVar->bool(VmFs::chdir($pathVar->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('chdir() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'chdir() argument #1');

        return JitChdir::invoke($context, $path);
    }
}
