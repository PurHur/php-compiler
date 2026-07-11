<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link facade for socket_export_stream() — SocketExportStreamJitHelper (#6349).
 */
final class StringSocketExportStream
{
    private const STREAM_HANDLE_HELPER = 'PHPCompiler\\ext\\sockets\\SocketExportStreamJitHelper::streamHandleForSocket';

    public static function ensureLinked(Context $context): void
    {
        SocketExportStreamRuntime::ensureLinked($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        return SocketExportStreamRuntime::streamHandleHelper($context);
    }
}
