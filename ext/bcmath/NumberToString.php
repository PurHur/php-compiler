<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** BcMath\Number::__toString() — VM (#7220). */
final class NumberToString extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::__toString()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmBcMathNumber::valueString($receiver));
        }
    }
}
