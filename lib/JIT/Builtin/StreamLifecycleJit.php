<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Stream lifecycle dispatch — embed PHP helper vs standalone LLVM (#9442).
 *
 * Replaces __compiler_is_resource / __compiler_fclose / __compiler_feof / __compiler_fflush
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamLifecycleJit
{
    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StreamLifecycleStandaloneLlvm::implement($context);

            return;
        }

        StreamLibcHandleRuntime::ensureLinked($context);
        StreamLifecycleRuntime::ensureLinked($context);
    }

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_resource',
        '__compiler_fclose',
        '__compiler_pclose',
        '__compiler_feof',
        '__compiler_fflush',
    ];

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
                throw new \LogicException($name.' missing after StreamLifecycleJit dispatch (#9442)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
