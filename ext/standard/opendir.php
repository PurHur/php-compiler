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

/** opendir() — VM via VmDir; JIT/AOT via __compiler_opendir (issue #3235). */
final class opendir extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('opendir() requires exactly one argument in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('opendir() path must be a string in this compiler build');
        }
        $handle = VmDir::opendir($pathVar->toString());
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->dirHandle($handle);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('opendir() requires exactly one argument in this compiler build');
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);

        return JitOpendir::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'opendir() path')
        );
    }
}
