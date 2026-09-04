<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\ZipExtensionHooks;
use PHPLLVM\Value;

/**
 * zip surfaces for lib/JIT Call ZipArchive* (#36204).
 *
 * php-src: ext/zip/php_zip.c — ZipArchive construct + method thin-AOT.
 * Registered from {@see Module::jitInit} so Call files do not import ext/zip.
 */
final class JitZipExtensionHooksFacade implements ZipExtensionHooks
{
    public function zipArchiveConstruct(Context $context, JITVariable ...$args): Value
    {
        return JitZipArchiveConstruct::invoke($context, ...$args);
    }

    public function zipArchiveMethod(Context $context, string $method, JITVariable ...$args): Value
    {
        return JitZipArchiveMethodDispatch::invoke($context, $method, ...$args);
    }
}
