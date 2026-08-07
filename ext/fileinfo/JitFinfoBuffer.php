<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\JIT\Builtin\FinfoBufferRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for finfo_buffer() / finfo::buffer() via FinfoBufferRuntime (#28660).
 *
 * Boxes {@see FinfoFileJitHelper::mimeFromBuffer} `__string__*` into `__value__*` string
 * (peer {@see JitFinfoFile}; buffer always yields a MIME string).
 *
 * php-src: ext/fileinfo/fileinfo.c — PHP_FUNCTION(finfo_buffer) / zim_finfo_buffer
 */
final class JitFinfoBuffer
{
    /**
     * @param list<JITVariable> $args finfo_buffer($finfo, $string, $flags = 0, $context = null)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_buffer() expects at least 2 arguments, %d given',
                $argc
            ));
        }

        return self::invokeBuffer(
            $context,
            $args[1],
            'finfo_buffer',
            1,
            'string'
        );
    }

    /**
     * @param list<JITVariable> $args finfo::buffer($string, …) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo::buffer() expects at least 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }

        return self::invokeBuffer(
            $context,
            $args[1],
            'finfo::buffer',
            0,
            'string'
        );
    }

    private static function invokeBuffer(
        Context $context,
        JITVariable $bufferArg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $dataStr = JitStringBuiltinArg::lower($context, $bufferArg, $function, $argIndex, $paramName);
        $raw = FinfoBufferRuntime::invoke($context, $dataStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
