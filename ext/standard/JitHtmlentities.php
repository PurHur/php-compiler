<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for htmlentities() — UTF-8 entity table via helper (#10734). */
final class JitHtmlentities
{
    public static function escape(Context $context, Value $strPtr, Value $flags): Value
    {
        return \PHPCompiler\JIT\Builtin\HtmlEntitiesJit::encode($context, $strPtr, $flags);
    }
}
