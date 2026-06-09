<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $from = VmIconv::coerceEncodingArg($frame->calledArgs[0], 'iconv', 0, 'from_encoding');
        $to = VmIconv::coerceEncodingArg($frame->calledArgs[1], 'iconv', 1, 'to_encoding');
        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'iconv', 2, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIconv::iconv($from, $to, $input);
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

        $fromLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $inputLit = JitStringBuiltinArg::compileTimeLiteral($args[2]);
        if (null !== $fromLit && null !== $toLit && null !== $inputLit) {
            $converted = VmIconv::iconv($fromLit, $toLit, $inputLit);
            if (false === $converted) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromString($converted);
        }

        throw new \LogicException('iconv() is not lowered for JIT/AOT in this compiler build');
    }
}
