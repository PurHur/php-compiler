<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Zstd\\compress_add() — PECL namespaced alias of zstd_compress_add() (#28079). */
final class ns_compress_add extends Internal
{
    private zstd_compress_add $delegate;

    public function __construct()
    {
        parent::__construct('Zstd\\compress_add');
        $this->delegate = new zstd_compress_add();
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
