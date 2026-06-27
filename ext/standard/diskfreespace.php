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
    public function __construct()
    {
        parent::__construct('diskfreespace');
    }

    public function execute(Frame $frame): void
    {
        (new disk_free_space())->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('diskfreespace() accepts at most one argument in this compiler build');
        }
        return JitDiskPath::lowerDiskSpaceBoxed($context, $args[0] ?? null, 'diskfreespace', true);
    }
}
