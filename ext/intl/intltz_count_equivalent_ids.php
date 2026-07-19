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
 * intltz_count_equivalent_ids() — procedural IntlTimeZone::countEquivalentIDs
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_count_equivalent_ids extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_count_equivalent_ids');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_count_equivalent_ids() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'intltz_count_equivalent_ids',
            0,
            'zoneId'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlTimeZone::countEquivalentIDs($id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_count_equivalent_ids() is not implemented for JIT in this compiler build (issue #20925)');
    }
}