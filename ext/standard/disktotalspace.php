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
    public function __construct()
    {
        parent::__construct('disktotalspace');
    }

    public function execute(Frame $frame): void
    {
        (new disk_total_space())->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('disktotalspace() accepts at most one argument in this compiler build');
        }
        return JitDiskPath::lowerDiskSpaceBoxed($context, $args[0] ?? null, 'disktotalspace', false);
    }
}
