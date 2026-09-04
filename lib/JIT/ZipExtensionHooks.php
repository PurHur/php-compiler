<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * zip extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/zip/JitZipExtensionHooksFacade.php}; Call
 * ZipArchive* files must not import {@code ext\zip}.
 */
interface ZipExtensionHooks
{
    /** ZipArchive::__construct() thin-AOT stub property seed. */
    public function zipArchiveConstruct(Context $context, Variable ...$args): Value;

    /** ZipArchive instance / static method thin-AOT dispatch. */
    public function zipArchiveMethod(Context $context, string $method, Variable ...$args): Value;
}
