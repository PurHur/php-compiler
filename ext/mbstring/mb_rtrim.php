<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_rtrim() — multibyte right trim (php-src ext/mbstring/mbstring.c; PHP 8.4, #5957).
 */
final class mb_rtrim extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_rtrim');
    }

    public function execute(Frame $frame): void
    {
        VmMbstring::runTrimBuiltin($frame, 'mb_rtrim', VmMbstring::MB_RTRIM);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbTrim::invoke($context, VmMbstring::MB_RTRIM, 'mb_rtrim', $args);
    }
}
