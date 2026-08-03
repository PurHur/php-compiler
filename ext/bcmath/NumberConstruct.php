<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** BcMath\Number::__construct(string|int $num) — VM (#7220); float→int ZPP (#24625). */
final class NumberConstruct extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('BcMath\\Number::__construct() called without $this');
        }
        $receiver = VmBcMathNumber::requireNumberReceiver($frame->calledArgs[0], 'BcMath\\Number::__construct()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::__construct() expects exactly 1 argument, 0 given');
        }
        $numVar = $frame->calledArgs[1]->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($numVar)) {
            throw new \TypeError('BcMath\\Number::__construct(): Argument #1 ($num) must be of type string|int, '
                .EnumCaseSupport::typeNameForVariable($numVar).' given');
        }
        // Stub is string|int only — reject objects (incl. Number) with Zend's union (#24626).
        if (Variable::TYPE_OBJECT === $numVar->type) {
            throw new \TypeError('BcMath\\Number::__construct(): Argument #1 ($num) must be of type string|int, '
                .$numVar->toObject()->class->name.' given');
        }
        $value = match ($numVar->type) {
            Variable::TYPE_INTEGER => (string) $numVar->toInt(),
            default => VmBcMathNumber::coerceOperand($frame->calledArgs[1], 'BcMath\\Number::__construct', 1, 'num'),
        };
        VmBcMathNumber::initObject($receiver, $value);
    }
}
