<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for remaining phpc_fs_dir.c runtime symbols (#6982).
 */
final class StringFsDirJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_copy',
        '__compiler_resolve_sidecar_source_path',
        '__compiler_touch',
        '__compiler_mkdir',
        '__phpc_stat',
        '__compiler_sys_get_temp_dir',
        '__compiler_tempnam',
        '__compiler_chgrp',
        '__compiler_chown',
        '__compiler_ftok',
    ];

    public static function implement(Context $context): void
    {
        // Do not early-return on __compiler_copy having a body: CopyRuntime alone
        // does not implement touch/mkdir/tempnam. Type always-on empty shells used
        // to paper that over; after those drops, skip-on-copy throws
        // "missing after StringFsDirJit implement" (#32510 / peer #32466).
        CopyRuntime::ensureLinked($context);
        ResolveSidecarRuntime::ensureLinked($context);
        FsDirRuntime::ensureLinked($context);
        SysGetTempDirRuntime::ensureLinked($context);
        StatArrayRuntime::ensureLinked($context);
        FtokRuntime::ensureLinked($context);
        ChownRuntime::ensureLinked($context);
        self::registerLinkedRuntime($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFsDirJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
