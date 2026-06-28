<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Stream read dispatch — JIT embed and AOT standalone via StreamReadRuntime PHP (#9393, #12937).
 *
 * php-src: ext/standard/flock.c, ext/standard/streams.c, ext/standard/file.c
 */
final class StreamReadJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_flock',
        '__compiler_fpassthru',
        '__compiler_ftruncate',
        '__compiler_ftell',
        '__compiler_fgetc',
        '__compiler_fgets',
        '__compiler_stream_get_line',
        '__compiler_fseek',
        '__compiler_stream_get_contents',
        '__compiler_stream_copy_to_stream',
        '__compiler_stream_copy_to_string',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        StreamFilter::ensureLinked($context);
        StreamReadRuntime::ensureLinked($context);
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
                throw new \LogicException($name.' missing after StreamReadJit dispatch (#9393)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
