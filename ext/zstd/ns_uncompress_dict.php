<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Zstd\\uncompress_dict() — PECL namespaced alias of zstd_uncompress_dict() (#28079). */
final class ns_uncompress_dict extends Internal
{
    private zstd_uncompress_dict $delegate;

    public function __construct()
    {
        parent::__construct('Zstd\\uncompress_dict');
        $this->delegate = new zstd_uncompress_dict();
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
