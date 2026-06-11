<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_ltrim() — multibyte left trim (php-src ext/mbstring/mbstring.c; PHP 8.4, #5957).
 */
final class mb_ltrim extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ltrim');
    }

    public function execute(Frame $frame): void
    {
        VmMbstring::runTrimBuiltin($frame, 'mb_ltrim', VmMbstring::MB_LTRIM);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ltrim() is not lowered for JIT/AOT in this compiler build');
    }
}
