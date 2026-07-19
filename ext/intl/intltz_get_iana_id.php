<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intltz_get_iana_id() — procedural IntlTimeZone::getIanaID
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925 / #20926).
 */
final class intltz_get_iana_id extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_iana_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_iana_id() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'intltz_get_iana_id',
            0,
            'zoneId'
        );
        $iana = VmIntlTimeZone::getIanaID($id);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $iana) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($iana);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_iana_id() is not implemented for JIT in this compiler build (issue #20925)');
    }
}