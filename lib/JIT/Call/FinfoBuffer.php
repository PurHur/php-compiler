<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\fileinfo\JitFinfoBuffer;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * finfo::buffer() — JIT/AOT MIME sniff via FinfoFileJitHelper (#28660).
 *
 * php-src: ext/fileinfo/fileinfo.c — zim_finfo_buffer
 */
final class FinfoBuffer implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitFinfoBuffer::invokeMethod($context, ...$args);
    }
}
