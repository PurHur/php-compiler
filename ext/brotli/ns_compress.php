<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Brotli\compress() — PECL namespaced alias of brotli_compress() (#28092).
 *
 * Class name avoids case-collision with procedural {@see brotli_compress}.
 */
final class ns_compress extends Internal
{
    private brotli_compress $delegate;

    public function __construct()
    {
        parent::__construct('Brotli\\compress');
        $this->delegate = new brotli_compress();
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
