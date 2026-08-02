<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\ext\standard\JitStreamIoKernel;

/**
 * Stream I/O dispatch — JIT embed + AOT standalone (#5343, #10326, #12956, #20229, #26929).
 *
 * Thin standalone / user-script AOT (`isThinStandaloneAotMain`): {@see JitStreamIoKernel}
 * libc + handle-table (NestedJIT VmFs returns handle 0 — ExternalMethod stub, #16075 / #26929).
 * Embed JIT: {@see StreamIoRuntime} NestedJIT {@see StreamIoJitHelper}.
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamIoJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_fwrite',
        '__compiler_fopen',
        '__compiler_popen',
        '__compiler_tmpfile',
        '__compiler_fread',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureStreamGlobals($context);
        StreamFilter::ensureLinked($context);

        // Thin/user-script AOT: libc + handle-table kernels (#16075 / #19462 / #26929).
        if ($context->isThinStandaloneAotMain()) {
            JitStreamIoKernel::implementForUserScriptLowering($context);

            return;
        }

        StreamIoRuntime::ensureLinked($context);
    }

    /** Stream handle globals for stream_socket_pair() without pulling full I/O emitters (#3437). */
    public static function ensureStreamGlobals(Context $context): void
    {
        StreamGlobalsJit::ensureGlobals($context);
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            if (!StreamIoRuntime::isStreamIoBridgeLinked($context, $name)) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamIoJit dispatch (#10326)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
