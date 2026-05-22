<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** strchr() is a PHP alias of strstr() (not libc strchr). */
final class strchr extends Internal
{
    private static ?strstr $delegate = null;

    private static function delegate(): strstr
    {
        return self::$delegate ??= new strstr();
    }

    public function execute(Frame $frame): void
    {
        self::delegate()->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return self::delegate()->call($context, ...$args);
    }
}
