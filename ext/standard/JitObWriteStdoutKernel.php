<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_ob_write_stdout_kernel() — thin libc write(1, …) (#21469).
 *
 * Nested leaf inside {@see ObOutputJitHelper::writeStdout} only. Must not use `echo` —
 * NestedJIT lowers echo to `__phpc_ob_echo_*` → `__phpc_ob_append_bytes` → appendString
 * → writeStdout (infinite recursion; emptied HelloWorld under user-script AOT, #21066).
 * php-src: main/output.c / SAPI write path
 */
final class JitObWriteStdoutKernel
{
    /** @param Value $chunk `__string__*` */
    public static function invoke(Context $context, Value $chunk): void
    {
        LibcExtern::register($context);
        // Module-local write(2) after LibcExtern always-on drop (#31817).
        LibcExtern::ensurePosixFd($context);

        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $data = $context->builder->structGep($chunk, $map['value']);
        $len = $context->builder->load($context->builder->structGep($chunk, $map['length']));
        $context->builder->call(
            $context->lookupFunction('write'),
            $i32->constInt(1, false),
            $data,
            $context->builder->zExt($len, $i64)
        );
    }
}
