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
 * intl_is_failure() — whether a UErrorCode value is a failure (php-src ext/intl/intl_error.c; #5156).
 */
final class intl_is_failure extends Internal
{
    public function __construct()
    {
        parent::__construct('intl_is_failure');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'intl_is_failure', 1);
        $code = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0],
            'intl_is_failure',
            0,
            'error_code'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(IntlError::isFailure($code));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'intl_is_failure() JIT runtime lowering is deferred; use VM (#5156)'
        );
    }
}
