<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\zip\JitZipArchive;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ZipArchive::{open,addFromString,close,getFromName} — thin AOT NestedJIT (#35424).
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_open / addFromString / close / getFromName
 */
final class ZipArchiveMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitZipArchive::invoke($context, $this->method, ...$args);
    }
}
