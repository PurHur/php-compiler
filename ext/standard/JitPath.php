<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringPath;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * dirname()/basename() JIT lowering via PathJitHelper PHP (#15286).
 *
 * Replaces ~470 LOC inline LLVM; SSOT: {@see VmString}.
 */
final class JitPath
{
    public static function dirname(Context $context, Value $str): Value
    {
        return StringPath::invokeDirname($context, $str);
    }

    public static function dirnameWithLevels(Context $context, Value $path, Value $levels): Value
    {
        return StringPath::invokeDirnameWithLevels($context, $path, $levels);
    }

    public static function basename(Context $context, Value $str): Value
    {
        return StringPath::invokeBasename($context, $str);
    }

    public static function basenameWithSuffix(Context $context, Value $path, Value $suffix): Value
    {
        return StringPath::invokeBasenameWithSuffix($context, $path, $suffix);
    }
}
