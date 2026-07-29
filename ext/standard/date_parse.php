<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * date_parse() — parse free-form datetime string into components (#6172).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_parse)
 */
final class date_parse extends Internal
{
    public function __construct()
    {
        parent::__construct('date_parse');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'date_parse() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#24862; reverts #20227 TypeError).
        // TypeError for null→string is PHP 9.0 (RFC deprecate_null_to_scalar_internal_arg), not 8.4.
        $date = VmString::trimFamilyStringArgForFrame($frame, 0, 'date_parse', 0, 'datetime');
        $frame->returnVar->array(VmDate::parseResultToHashTable(VmDateTimeNative::parseDate($date)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateParse::invokeDateParse($context, ...$args);
    }
}
