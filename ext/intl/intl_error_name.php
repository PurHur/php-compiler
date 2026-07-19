<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * intl_error_name() — ICU u_errorName for a UErrorCode (php-src ext/intl/intl_error.c; #20872).
 */
final class intl_error_name extends Internal
{
    public function __construct()
    {
        parent::__construct('intl_error_name');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'intl_error_name', 1);
        $code = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0],
            'intl_error_name',
            0,
            'errorCode'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(IntlError::errorName($code));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'intl_error_name() JIT runtime lowering is deferred; use VM (#20872)'
        );
    }
}
