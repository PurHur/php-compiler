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

/** tempnam() — VM via VmFs; JIT/AOT via libc tempnam(3) (issue #1201). */
final class tempnam extends Internal
{
    public function __construct()
    {
        parent::__construct('tempnam');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('tempnam() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dir = $frame->calledArgs[0]->resolveIndirect();
        $prefix = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $dir->type || Variable::TYPE_STRING !== $prefix->type) {
            throw new \LogicException('tempnam() requires string directory and prefix in this compiler build');
        }
        $path = VmFs::tempnam($dir->toString(), $prefix->toString());
        if (false === $path) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($path);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('tempnam() requires exactly two arguments in this compiler build');
        }

        return JitTempnam::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'tempnam() directory'),
            JitStringArg::lower($context, $args[1], 'tempnam() prefix')
        );
    }
}
