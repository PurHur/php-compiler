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
 * intltz_get_id_for_windows_id() — procedural IntlTimeZone::getIDForWindowsID
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_get_id_for_windows_id extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_id_for_windows_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_id_for_windows_id() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $windowsId = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'intltz_get_id_for_windows_id',
            0,
            'windowsID'
        );
        $region = null;
        if (2 === $argc) {
            $r = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $r->type) {
                $region = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'intltz_get_id_for_windows_id',
                    1,
                    'region'
                );
            }
        }
        $olson = VmIntlTimeZone::getIDForWindowsID($windowsId, $region);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $olson) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($olson);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_id_for_windows_id() is not implemented for JIT in this compiler build (issue #20925)');
    }
}