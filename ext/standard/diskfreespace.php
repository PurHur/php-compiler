<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
        (new disk_free_space('diskfreespace'))->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30552 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'diskfreespace', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitDiskPath::lowerDiskSpaceBoxed($context, $args[0], 'diskfreespace', true);
    }
}
