<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Stream buffer dispatch — JIT embed + AOT standalone via StreamBufferRuntime PHP (#5343, #14462).
 *
 * php-src: ext/standard/streams.c
 */
final class StreamBufferJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_set_chunk_size',
        '__compiler_stream_set_timeout',
        '__compiler_stream_set_write_buffer',
        '__compiler_stream_set_read_buffer',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        StreamBufferRuntime::ensureLinked($context);
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            // Inventory defer stubs (`entry` only) must not count as linked (#30788 / peer #19462).
            if (null === $fn || 0 === $fn->countBasicBlocks() || StreamIoRuntime::isDeferStub($fn)) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks() || StreamIoRuntime::isDeferStub($fn)) {
                throw new \LogicException($name.' missing after StreamBufferJit dispatch (#14462)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
