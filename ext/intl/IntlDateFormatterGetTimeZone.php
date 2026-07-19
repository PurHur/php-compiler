<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** IntlDateFormatter::getTimeZone() — php-src datefmt_get_timezone. */
final class IntlDateFormatterGetTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getTimeZone() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getTimeZone() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getTimeZone($receiver->toObject(), $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result);
    }
}
