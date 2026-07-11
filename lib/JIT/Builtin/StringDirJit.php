<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Directory handle dispatch — embed and standalone route through DirHandleJitHelper PHP (#11811, #12870).
 *
 * php-src: ext/standard/dir.c — opendir/readdir/closedir/rewinddir
 */
final class StringDirJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_dir_resource',
        '__compiler_opendir',
        '__compiler_readdir',
        '__compiler_closedir',
        '__compiler_rewinddir',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        StringDirRuntime::ensureLinked($context);
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
                throw new \LogicException($name.' missing after StringDirJit dispatch (#11811)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
