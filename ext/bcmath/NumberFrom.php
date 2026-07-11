<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** BcMath\Number::from(string|int $num) — static factory (php-src ext/bcmath/bcmath.c; #16814). */
final class NumberFrom extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('from');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('BcMath\\Number::from() expects exactly 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('BcMath\\Number::from() requires VM context in this compiler build');
        }
        $numVar = $frame->calledArgs[0]->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($numVar)) {
            throw new \TypeError('BcMath\\Number::from(): Argument #1 ($num) must be of type string|int, '
                .EnumCaseSupport::typeNameForVariable($numVar).' given');
        }
        $value = match ($numVar->type) {
            Variable::TYPE_INTEGER => (string) $numVar->toInt(),
            default => VmBcMathNumber::coerceOperand($frame->calledArgs[0], 'BcMath\\Number::from', 1, 'num'),
        };
        $this->returnNumber($frame, $value, null);
    }
}
