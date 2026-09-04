<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FileinfoExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fileinfo surfaces for lib/JIT Call Finfo* (#36204).
 *
 * php-src: ext/fileinfo/fileinfo.c — zim_finfo_set_flags / zim_finfo_buffer / zim_finfo_file.
 * Registered from {@see Module::jitInit} so Call files do not import ext/fileinfo.
 */
final class JitFileinfoExtensionHooksFacade implements FileinfoExtensionHooks
{
    public function setFlags(Context $context, bool $method, JITVariable ...$args): Value
    {
        return finfo_set_flags::lowerSetFlags($context, $method, ...$args);
    }

    public function bufferMethod(Context $context, JITVariable ...$args): Value
    {
        return JitFinfoBuffer::invokeMethod($context, ...$args);
    }

    public function fileMethod(Context $context, JITVariable ...$args): Value
    {
        return JitFinfoFile::invokeMethod($context, ...$args);
    }
}
