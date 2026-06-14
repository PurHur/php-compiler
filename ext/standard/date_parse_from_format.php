<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * date_parse_from_format() — parse format-string datetime into components (#6172).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_parse_from_format)
 */
final class date_parse_from_format extends Internal
{
    public function __construct()
    {
        parent::__construct('date_parse_from_format');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'date_parse_from_format() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $format = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'date_parse_from_format',
            0,
            'format'
        );
        $date = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'date_parse_from_format',
            1,
            'date'
        );
        $frame->returnVar->array(VmDate::parseResultToHashTable(
            VmDateTimeNative::parseFromFormatComponents($format, $date)
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateParse::invokeDateParseFromFormat($context, ...$args);
    }
}
