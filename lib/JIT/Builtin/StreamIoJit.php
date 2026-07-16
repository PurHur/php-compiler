<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;

/**
 * Stream I/O dispatch — JIT embed + AOT standalone via StreamIoRuntime PHP (#5343, #10326, #12956).
 *
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

        // User-script AOT: libc + handle-table kernels (VmFs nested helpers skip __init__, #16075 / #19462).
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StreamIoStandaloneLlvm::implementForUserScriptLowering($context);

            return;
        }

        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            StreamIoRuntime::implementDeferredStreamIoStubs($context);

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
