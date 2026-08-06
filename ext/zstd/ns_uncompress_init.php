<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Zstd\\uncompress_init() — PECL namespaced alias of zstd_uncompress_init() (#28079). */
final class ns_uncompress_init extends Internal
{
    private zstd_uncompress_init $delegate;

    public function __construct()
    {
        parent::__construct('Zstd\\uncompress_init');
        $this->delegate = new zstd_uncompress_init();
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
