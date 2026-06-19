<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** @deprecated compat shim — logic lives in {@see StringStrtok} (#9812). */
final class StringStrtokJit
{
    public static function implement(Context $context): void
    {
        StringStrtok::implement($context);
    }
}
