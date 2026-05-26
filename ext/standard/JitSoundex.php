<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSoundex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for soundex() via phpc_soundex (issue #2416). */
final class JitSoundex
{
    public static function invoke(Context $context, Value $input): Value
    {
        StringSoundex::ensureLinked($context);
        $result = $context->builder->call(
            $context->lookupFunction('phpc_soundex'),
            $input
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $result
        );

        return $ptr;
    }
}
