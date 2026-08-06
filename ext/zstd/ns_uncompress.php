<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Zstd\\uncompress() — PECL namespaced alias of zstd_uncompress() (#28079). */
final class ns_uncompress extends Internal
{
    private zstd_uncompress $delegate;

    public function __construct()
    {
        parent::__construct('Zstd\\uncompress');
        $this->delegate = new zstd_uncompress();
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
