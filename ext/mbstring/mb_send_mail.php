<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_send_mail() — multibyte MIME mail send (php-src ext/mbstring/mbstring.c; #6548).
 */
final class mb_send_mail extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_send_mail');
    }

    public function execute(Frame $frame): void
    {
        VmMbstring::runSendMailBuiltin($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_send_mail() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
