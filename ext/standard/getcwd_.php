<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getcwd() — VM via VmFs; JIT/AOT via realpath(3) on ".". */
final class getcwd_ extends Internal
{
    public function __construct()
    {
        parent::__construct('getcwd');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('getcwd() takes no arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $cwd = VmFs::getcwd();
        if (false === $cwd) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($cwd);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('getcwd() takes no arguments in this compiler build');
        }

        $resolved = JitGetcwd::invoke($context);

        return JitGetcwd::boxed($context, $resolved);
    }
}
