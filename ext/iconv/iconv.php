<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * iconv() — charset conversion (php-src ext/iconv/iconv.c; #6251, pairs #3222).
 */
final class iconv extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'iconv() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR encodings — soft-null DEP on default profile (#31309); TypeError on 8.4 / strict (#19387).
        $from = VmIconv::coerceEncodingArg($frame->calledArgs[0], 'iconv', 0, 'from_encoding', $frame);
        $to = VmIconv::coerceEncodingArg($frame->calledArgs[1], 'iconv', 1, 'to_encoding', $frame);
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' (php-src iconv.c / #21197).
        $input = VmString::trimFamilyStringArgForFrame($frame, 2, 'iconv', 2, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIconv::iconv($from, $to, $input, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('iconv() requires exactly three arguments');
        }

        return JitIconv::invoke($context, ...$args);
    }
}
