<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** bcscale() — set or get default bcmath scale (php-src ext/bcmath/bcmath.c; issue #3365). */
final class bcscale extends Internal
{
    public function __construct()
    {
        parent::__construct('bcscale');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('bcscale() accepts zero or one argument in this compiler build');
        }
        $scale = null;
        if (1 === $argc) {
            $var = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $var->type) {
                throw new \LogicException('bcscale() scale must be an integer in this compiler build');
            }
            $scale = $var->toInt();
        }
        $frame->returnVar->int(VmBcmath::scale($scale));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitBcmath::scale($context, ...$args);
    }
}
