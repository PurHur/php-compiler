<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathNextafter;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * nextafter() — IEEE next representable float (PHP 8.4, ext/standard/math.c).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class nextafter extends Internal
{
    private const FUNCTION = 'nextafter';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(self::FUNCTION.'() requires exactly two arguments');
        }
        $num = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num',
            $frame
        );
        $next = VmMath::parseForwardProfileStrictDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'next',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::nextafter($num, $next));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(self::FUNCTION.'() requires exactly two arguments');
        }
        $num = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', self::FUNCTION, 'float');
        $next = JitFdiv::lowerSingleOperand($context, $args[1], 2, 'next', self::FUNCTION, 'float', true);

        return MathNextafter::invoke($context, $num, $next);
    }
}
