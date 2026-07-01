<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzputs() — alias of gzwrite() for string writes (ext/zlib/zlib.c, #14596). */
final class gzputs extends Internal
{
    private gzwrite $delegate;

    public function __construct()
    {
        parent::__construct('gzputs');
        $this->delegate = new gzwrite('gzputs');
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
