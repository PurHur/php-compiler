<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Zstd\\compress_dict() — PECL namespaced alias of zstd_compress_dict() (#28079). */
final class ns_compress_dict extends Internal
{
    private zstd_compress_dict $delegate;

    public function __construct()
    {
        parent::__construct('Zstd\\compress_dict');
        $this->delegate = new zstd_compress_dict();
    }

    public function execute(Frame $frame): void
    {
        $this->delegate->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return $this->delegate->call($context, ...$args);
    }
}
