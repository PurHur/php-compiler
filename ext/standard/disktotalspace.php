<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
        (new disk_total_space('disktotalspace'))->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30552 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'disktotalspace', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitDiskPath::lowerDiskSpaceBoxed($context, $args[0], 'disktotalspace', false);
    }
}
