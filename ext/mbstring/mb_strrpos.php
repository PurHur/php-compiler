<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_strrpos() — reverse multibyte search (php-src ext/mbstring/mbstring.c; #7015).
 */
final class mb_strrpos extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strrpos');
    }

    public function execute(Frame $frame): void
    {
        if (!isset($frame->calledArgs[0]) || !isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError(sprintf(
                'mb_strrpos() expects at least 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (isset($frame->calledArgs[4])) {
            throw new \ArgumentCountError(sprintf(
                'mb_strrpos() expects at most 4 arguments, %d given',
                max(\array_keys($frame->calledArgs)) + 1
            ));
        }
        $haystack = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strrpos', 0, 'haystack');
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_strrpos', 1, 'needle');
        $offset = isset($frame->calledArgs[2])
            ? VmMbstring::coerceOffsetArg($frame, 'mb_strrpos', 2)
            : 0;
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_strrpos', 3)
            : 'UTF-8';
        $result = VmMbstring::strrpos($haystack, $needle, $offset, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strrpos() requires two to four arguments');
        }
        $folded = JitMbSearch::tryStrrposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException('mb_strrpos() is not lowered for JIT/AOT in this compiler build');
    }
}
