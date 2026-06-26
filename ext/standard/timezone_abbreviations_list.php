<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * timezone_abbreviations_list() — timelib abbreviation map (ext/date/php_date.c, #11874).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_abbreviations_list)
 */
final class timezone_abbreviations_list extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_abbreviations_list');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->copyFrom(VmDateTimeNative::timezoneAbbreviationsListVariable());
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'timezone_abbreviations_list() is not implemented for JIT in this compiler build'
        );
    }
}
