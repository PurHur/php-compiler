<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/**
 * IntlDateFormatter::getPattern() — php-src datefmt_get_pattern (#3336).
 */
final class IntlDateFormatterGetPattern extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Instance method: $this only
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getPattern() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \LogicException('IntlDateFormatter::getPattern() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getPattern($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}
