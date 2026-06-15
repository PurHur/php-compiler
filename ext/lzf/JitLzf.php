<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

use PHPCompiler\JIT\Builtin\StringLzf;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for lzf_compress()/lzf_decompress() — LzfJitHelper in-module (#6384, #8805). */
final class JitLzf
{
    public static function compress(Context $context, Value $source): Value
    {
        StringLzf::ensureLinked($context);

        return $context->builder->call(StringLzf::compressHelper($context), $source);
    }

    public static function decompress(Context $context, Value $source): Value
    {
        StringLzf::ensureLinked($context);

        return $context->builder->call(StringLzf::decompressHelper($context), $source);
    }
}
