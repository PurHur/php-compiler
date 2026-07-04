<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\Lcg as LcgBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * lcg_value() — combined LCG float in [0, 1] (php-src ext/random/random.c, #3295).
 */
final class lcg_value extends Internal
{
    public function __construct()
    {
        parent::__construct('lcg_value');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'lcg_value', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmCombinedLcg::value());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->requireExactJitArgCount($context, $args, 'lcg_value', 0);
        LcgBuiltin::ensureLinked($context);

        return $context->builder->call(LcgBuiltin::value($context));
    }
}
