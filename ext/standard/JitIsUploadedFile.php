<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringFsDir;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for is_uploaded_file() via __compiler_is_uploaded_file (issue #2204). */
final class JitIsUploadedFile
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        StringFsDir::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_is_uploaded_file'),
            $pathStr
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
