<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getcwd() — current working directory (VM: VmFs; JIT/AOT via __compiler_getcwd). */
final class getcwd_ extends Internal
{
    public function __construct()
    {
        parent::__construct('getcwd');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('getcwd() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmFs::getcwd();
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('getcwd() takes no arguments');
        }

        return JitGetcwd::invoke($context);
    }
}
