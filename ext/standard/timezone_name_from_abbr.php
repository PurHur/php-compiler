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
 * timezone_name_from_abbr() — abbreviation lookup (ext/date/php_date.c, #10957).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_name_from_abbr)
 */
final class timezone_name_from_abbr extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_name_from_abbr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('timezone_name_from_abbr() expects between 1 and 3 arguments, %d given', $argc)
            );
        }
        $abbr = $frame->calledArgs[0]->resolveIndirect()->toString();
        $gmtoffset = -1;
        if ($argc >= 2) {
            $gmtoffset = (int) $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $isdst = -1;
        if ($argc >= 3) {
            $isdst = (int) $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        $tzid = VmDateTimeNative::timezoneNameFromAbbr($abbr, $gmtoffset, $isdst);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($tzid): void {
            if (false === $tzid) {
                $ret->bool(false);
            } else {
                $ret->string($tzid);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'timezone_name_from_abbr() is not implemented for JIT in this compiler build'
        );
    }
}
