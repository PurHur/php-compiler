<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\fileinfo\JitFinfoFile;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * finfo::file() — JIT/AOT MIME sniff via VmMime (#27196, re-#3366).
 *
 * php-src: ext/fileinfo/fileinfo.c — zim_finfo_file
 */
final class FinfoFile implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitFinfoFile::invokeMethod($context, ...$args);
    }
}
