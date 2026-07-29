<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;

/**
 * BcMath\Number::divmod(Number|string|int $num, ?int $scale = null): array — VM (#24611).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, divmod).
 * Returns [quotient Number (scale 0), remainder Number (effective scale)].
 */
final class NumberDivmod extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('divmod');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::divmod()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::divmod() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::divmod', 'num');
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::divmod');
        // php-src: scale_is_null → MAX(intern->scale, num_full_scale); never bcscale default.
        $effectiveScale = $scale ?? max(
            VmBcMathNumber::objectScale($receiver),
            VmBcmath::decimalScale($right)
        );
        [$quotient, $remainder] = VmBcmath::divmod(
            VmBcMathNumber::valueString($receiver),
            $right,
            $effectiveScale
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('BcMath\\Number::divmod() requires VM context in this compiler build');
        }
        $ht = new HashTable();
        $ht->append(VmBcMathNumber::fromComputedValue($frame->vmContext, $quotient, 0));
        $ht->append(VmBcMathNumber::fromComputedValue($frame->vmContext, $remainder, $effectiveScale));
        $frame->returnVar->array($ht);
    }
}
