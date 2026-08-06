<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Zstd\\compress() — PECL namespaced alias of zstd_compress() (#28079). */
final class ns_compress extends Internal
{
    private zstd_compress $delegate;

    public function __construct()
    {
        parent::__construct('Zstd\\compress');
        $this->delegate = new zstd_compress();
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
