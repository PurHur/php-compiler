<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/**
 * gmp_binomial() — binomial coefficient C(n, k) (php-src ext/gmp/gmp.c; issue #20519).
 *
 * php-src: ZEND_FUNCTION(gmp_binomial) / mpz_bin_uiui / mpz_bin_ui
 */
final class gmp_binomial extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_binomial');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_binomial');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_binomial() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_binomial() requires VM context in this compiler build');
        }
        $n = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_binomial', 0, 'n');
        $k = VmGmp::coerceBinomialK($frame->calledArgs[1]);

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::binomial($n, $k));
    }
}
