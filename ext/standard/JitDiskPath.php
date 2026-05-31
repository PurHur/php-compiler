<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Lower disk_*_space() path args; null defaults to "." (php-src filestat.c). */
final class JitDiskPath
{
    /** @return Value */
    public static function lower(Context $context, ?JITVariable $arg, string $label): Value
    {
        if (null === $arg || JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString('.'));
        }

        return JitStringArg::lower($context, $arg, $label);
    }
}
