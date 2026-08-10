<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** bcdivmod() — quotient + remainder pair (php-src ext/bcmath/bcmath.c; PHP 8.4, #6966). */
final class bcdivmod extends Internal
{
    public function __construct()
    {
        parent::__construct('bcdivmod');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('bcdivmod() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR: caller strict_types → TypeError; else soft-null DEP+coerce (#29977).
        $left = VmString::stringBuiltinArgForFrame($frame, 0, 'bcdivmod', 0, 'num1');
        $right = VmString::stringBuiltinArgForFrame($frame, 1, 'bcdivmod', 1, 'num2');
        $scale = null;
        if (isset($frame->calledArgs[2])) {
            $scale = VmBcMathNumber::optionalScaleArg($frame->calledArgs[2], 'bcdivmod', 3, $frame);
        }
        [$quotient, $remainder] = VmBcmath::divmod($left, $right, $scale);

        $ht = new HashTable();
        $qVar = new Variable();
        $qVar->string($quotient);
        $rVar = new Variable();
        $rVar->string($remainder);
        $ht->append($qVar);
        $ht->append($rVar);
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitBcmath::divmod($context, ...$args);
    }
}
