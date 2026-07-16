<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/**
 * BcMath\Number::sqrt(?int $scale = null) — VM (#19582).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, sqrt).
 */
final class NumberSqrt extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('sqrt');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::sqrt()');
        $scale = $this->optionalScale($frame, 1, 'BcMath\\Number::sqrt');
        [$requestedScale, $auto] = $this->effectiveSqrtScale($receiver, $scale);
        $result = VmBcmath::sqrt(VmBcMathNumber::valueString($receiver), $requestedScale);
        if ($auto) {
            $result = $this->stripTrailingFracZeros($result);
            $objectScale = $this->shrinkAutoExpandScale($result, $requestedScale);
        } else {
            $objectScale = $requestedScale;
        }
        $this->returnNumber($frame, $result, $objectScale);
    }
}
