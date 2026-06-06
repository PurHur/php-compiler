<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** die() — alias of exit() (PHP 8.4 proper function form, issue #6975). */
final class die_ extends Internal
{
    public function __construct()
    {
        parent::__construct('die');
    }

    public function execute(Frame $frame): void
    {
        exit_::invokeFromFrame($frame, 'die');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return exit_::jitCall($context, 'die', ...$args);
    }
}
