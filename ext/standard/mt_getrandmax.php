<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mt_getrandmax() — MT upper bound (php-src ext/random/random.c, #17228).
 */
final class mt_getrandmax extends Internal
{
    public function __construct()
    {
        parent::__construct('mt_getrandmax');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'mt_getrandmax', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmMt19937::PHP_MT_RAND_MAX);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->requireExactJitArgCount($context, $args, 'mt_getrandmax', 0);
        $i64 = $context->getTypeFromString('int64');

        return $i64->constInt(VmMt19937::PHP_MT_RAND_MAX, false);
    }
}
