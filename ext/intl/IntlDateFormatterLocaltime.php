<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\HashTable;

/** IntlDateFormatter::localtime() — php-src datefmt_localtime. */
final class IntlDateFormatterLocaltime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('localtime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::localtime() expects between 1 and 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::localtime() called on incompatible object');
        }
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'IntlDateFormatter::localtime', 1, 'string');
        $offset = null;
        $hasOffset = $argc >= 3;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $offsetVar->type) {
                $offset = VmIntlDateFormatter::coerceIntArg($offsetVar, 'IntlDateFormatter::localtime', 2, 'offset');
            }
        }
        $result = VmIntlDateFormatter::localtime($receiver->toObject(), $text, $offset);
        if ($hasOffset && null !== $offset) {
            $frame->calledArgs[2]->byRefTarget()->int($offset);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        /** @var HashTable $result */
        $frame->returnVar->array($result);
    }
}
