<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** diskfreespace() — PHP alias of disk_free_space(). */
final class diskfreespace extends Internal
{
    private disk_free_space $delegate;

    public function __construct()
    {
        parent::__construct('diskfreespace');
        $this->delegate = new disk_free_space();
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
