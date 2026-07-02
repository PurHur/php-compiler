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
            'num'
        );
        $next = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'next'
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
        [$num, $next] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            self::FUNCTION,
            'num',
            'next'
        );

        return MathNextafter::invoke($context, $num, $next);
    }
}
