<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** preg_last_error_msg() — VM via host PCRE; JIT/AOT via __compiler_preg_last_error_msg (issue #3110). */
final class preg_last_error_msg_ extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_last_error_msg');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('preg_last_error_msg() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmPreg::lastErrorMsg());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('preg_last_error_msg() takes no arguments');
        }

        return JitPregLastErrorMsg::invoke($context);
    }
}
