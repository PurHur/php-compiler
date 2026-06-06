<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for calendar builtins (php-src ext/calendar/calendar.c; #7133).
 *
 * Phase 0 skeleton: register symbols; algorithms in #3742 / #6759.
 */
abstract class CalendarFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \LogicException($this->getName().'() is not implemented in this compiler build (issue #3742)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #3742)');
    }
}
