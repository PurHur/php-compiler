<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Brotli\uncompress_add() — PECL namespaced alias of brotli_uncompress_add() (#28092). */
final class ns_uncompress_add extends Internal
{
    private brotli_uncompress_add $delegate;

    public function __construct()
    {
        parent::__construct('Brotli\\uncompress_add');
        $this->delegate = new brotli_uncompress_add();
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
