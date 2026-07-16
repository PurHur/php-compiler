<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gmp_random_bits() — php-src ext/gmp/gmp.c; issue #19540. */
final class gmp_random_bits extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_random_bits');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_random_bits');

        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_random_bits() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_random_bits() requires VM context in this compiler build');
        }
        $bitsVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $bitsVar->type) {
            throw new \TypeError('gmp_random_bits(): Argument #1 ($bits) must be of type int');
        }
        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::randomBits($bitsVar->toInt()));

    }
}
