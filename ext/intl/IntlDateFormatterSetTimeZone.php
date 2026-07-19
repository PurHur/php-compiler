<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** IntlDateFormatter::setTimeZone() — php-src datefmt_set_timezone. */
final class IntlDateFormatterSetTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::setTimeZone() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::setTimeZone() called on incompatible object');
        }
        $ok = VmIntlDateFormatter::setTimeZone($receiver->toObject(), $frame->calledArgs[1], $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
