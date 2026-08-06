<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Brotli\compress_init() — PECL namespaced alias of brotli_compress_init() (#28092). */
final class ns_compress_init extends Internal
{
    private brotli_compress_init $delegate;

    public function __construct()
    {
        parent::__construct('Brotli\\compress_init');
        $this->delegate = new brotli_compress_init();
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
