<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** doubleval() — PHP alias of floatval(); delegates to native floatval implementation. */
final class doubleval extends Internal
{
    private floatval $delegate;

    public function __construct()
    {
        parent::__construct('doubleval');
        $this->delegate = new floatval();
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
