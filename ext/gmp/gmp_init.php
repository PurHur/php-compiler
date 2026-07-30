<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_init() — create GMP integer (php-src ext/gmp/gmp.c; issue #3341). */
final class gmp_init extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_init');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_init');
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'gmp_init() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        $base = 0;
        if (2 === $argc) {
            $baseVar = $frame->calledArgs[1]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $baseVar->type) {
                throw new \TypeError('gmp_init(): Argument #2 ($base) must be of type int');
            }
            $base = $baseVar->toInt();
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_init() requires VM context in this compiler build');
        }
        $signed = VmGmp::parseInitOperand($frame->calledArgs[0], 'gmp_init', 0, 'num', $base);

        return VmGmpObject::fromSignedDecimal($frame->vmContext, $signed);
    }
}
