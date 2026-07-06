<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * intl_get_error_message() — last intl error message (php-src ext/intl/intl_error.c; #5156).
 */
final class intl_get_error_message extends Internal
{
    public function __construct()
    {
        parent::__construct('intl_get_error_message');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'intl_get_error_message', 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(IntlError::getMessage());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'intl_get_error_message() JIT runtime lowering is deferred; use VM (#5156)'
        );
    }
}
