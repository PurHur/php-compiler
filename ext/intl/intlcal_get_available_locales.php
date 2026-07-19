<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * intlcal_get_available_locales() — procedural IntlCalendar::getAvailableLocales
 * (php-src calendar_methods.c / calendar.stub.php; #20897).
 */
final class intlcal_get_available_locales extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_get_available_locales');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_get_available_locales() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmIntlCalendar::getAvailableLocales());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_get_available_locales() is not implemented for JIT in this compiler build (issue #20897)');
    }
}
