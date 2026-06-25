<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Stream capability probes — embed PHP helper vs standalone LLVM (#5343, #11413).
 *
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamCapsJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_isatty',
        '__compiler_stream_is_local',
        '__compiler_stream_is_local_uri',
        '__compiler_stream_supports',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StreamCapsStandaloneLlvm::implement($context);
            StreamCapsRuntime::ensureLocalUriLinked($context);
        } else {
            StreamCapsRuntime::ensureLinked($context);
        }

        self::registerLinkedRuntime($context);
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
                throw new \LogicException($name.' missing after StreamCapsJit dispatch (#11413)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
