<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT lowering for fmin()/fmax() (#11728, php-src ext/standard/math.c). */
final class JitFminMax
{
    public static function invoke(Context $context, bool $pickMin, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException(
                \sprintf('f%s() expects at least 2 arguments in this compiler build', $pickMin ? 'min' : 'max')
            );
        }
        $double = $context->getTypeFromString('double');
        $name = $pickMin ? 'fmin' : 'fmax';
        $best = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', $name);
        foreach (\array_slice($args, 1) as $i => $arg) {
            $candidate = JitFdiv::lowerSingleOperand(
                $context,
                $arg,
                $i + 2,
                'nums',
                $name
            );
            $cmp = $context->builder->fcmp(
                $pickMin ? Builder::REAL_OGT : Builder::REAL_OLT,
                $best,
                $candidate
            );
            $best = $context->builder->select($cmp, $candidate, $best);
        }

        return $best;
    }
}
