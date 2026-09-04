<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ZipArchive::__construct — seed thin-AOT stub properties (#35002 leftover of #20584).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\zip} (#36204). php-src: ext/zip/php_zip.c — ze_zip_object defaults.
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class ZipArchiveConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireZip()->zipArchiveConstruct($context, ...$args);
    }
}
