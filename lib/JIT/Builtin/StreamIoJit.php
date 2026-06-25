<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Stream I/O dispatch — embed PHP helper vs standalone LLVM (#5343, #10326).
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

        if (StreamIoStandaloneLlvm::shouldDeferHeavyStreamIoEmitters($context)) {
            StreamIoStandaloneLlvm::implementDeferredStreamIoStubs($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StreamIoStandaloneLlvm::implement($context);

            return;
        }

        StreamIoRuntime::ensureLinked($context);
    }

    /** Stream handle globals for stream_socket_pair() without pulling full I/O emitters (#3437). */
    public static function ensureStreamGlobals(Context $context): void
    {
        StreamIoStandaloneLlvm::ensureStreamGlobals($context);
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
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
