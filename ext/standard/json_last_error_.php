<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** json_last_error() — VM via host JSON; JIT/AOT via __compiler_json_last_error (issue #1173). */
final class json_last_error_ extends Internal
{
    public function __construct()
    {
        parent::__construct('json_last_error');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('json_last_error() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmJson::lastError());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('json_last_error() takes no arguments');
        }

        return JitJsonLastError::invoke($context);
    }
}
