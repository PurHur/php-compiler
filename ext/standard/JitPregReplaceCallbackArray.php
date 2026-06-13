<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for preg_replace_callback_array() — chain JitPregReplaceCallback (#3568). */
final class JitPregReplaceCallbackArray
{
    public static function invoke(Context $context, JITVariable $patterns, JITVariable $subject): Value
    {
        throw new \LogicException(
            'preg_replace_callback_array() is not implemented for JIT/AOT in this compiler build; use bin/vm.php (#3568)'
        );
    }
}
