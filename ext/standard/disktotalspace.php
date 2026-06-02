<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** disktotalspace() — PHP alias of disk_total_space(). */
final class disktotalspace extends Internal
{
    private disk_total_space $delegate;

    public function __construct()
    {
        parent::__construct('disktotalspace');
        $this->delegate = new disk_total_space();
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
