<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** IntlDateFormatter::parse() — php-src datefmt_parse. */
final class IntlDateFormatterParse extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parse');
    }

    public function execute(Frame $frame): void
    {
        self::executeParse($frame, 'IntlDateFormatter::parse', false);
    }

    public static function executeParse(Frame $frame, string $method, bool $updateCalendar): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 1 and 2 arguments, %d given',
                $method,
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error($method.'() called on incompatible object');
        }
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $method, 1, 'string');
        $offset = null;
        $hasOffset = $argc >= 3;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $offsetVar->type) {
                $offset = VmIntlDateFormatter::coerceIntArg($offsetVar, $method, 2, 'offset');
            }
        }
        $result = VmIntlDateFormatter::parse($receiver->toObject(), $text, $offset, $updateCalendar);
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
        if (\is_float($result)) {
            $frame->returnVar->float($result);
        } else {
            $frame->returnVar->int((int) $result);
        }
    }
}
