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
        // Z_PARAM_STR — caller strict_types → TypeError on null (#30308; peer date_create_from_format).
        $format = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'date_parse_from_format',
            0,
            'format'
        );
        $date = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'date_parse_from_format',
            1,
            'datetime'
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
