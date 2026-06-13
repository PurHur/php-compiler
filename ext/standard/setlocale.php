<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * setlocale() — libc setlocale(3) wrapper (issue #6133, #3254).
 *
 * php-src: ext/standard/locale.c — PHP_FUNCTION(setlocale)
 */
final class setlocale extends Internal
{
    public function __construct()
    {
        parent::__construct('setlocale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                \sprintf('setlocale() expects at least 2 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $categoryVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $categoryVar->type) {
            throw new \TypeError(self::categoryTypeError($categoryVar));
        }

        $result = VmLocale::setlocale(
            $categoryVar->toInt(),
            \array_slice($frame->calledArgs, 1)
        );
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLocale::setlocale($context, ...$args);
    }

    private static function categoryTypeError(Variable $var): string
    {
        return \sprintf(
            'setlocale(): Argument #1 ($category) must be of type int, %s given',
            VmStreamArg::debugTypeName($var)
        );
    }
}
