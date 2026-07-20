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
 * mb_stripos() — case-insensitive multibyte search (php-src ext/mbstring/mbstring.c; #7015).
 */
final class mb_stripos extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_stripos');
    }

    public function execute(Frame $frame): void
    {
        if (!isset($frame->calledArgs[0]) || !isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError(sprintf(
                'mb_stripos() expects at least 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (isset($frame->calledArgs[4])) {
            throw new \ArgumentCountError(sprintf(
                'mb_stripos() expects at most 4 arguments, %d given',
                max(\array_keys($frame->calledArgs)) + 1
            ));
        }
        $haystack = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_stripos', 0, 'haystack');
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_stripos', 1, 'needle');
        $offset = isset($frame->calledArgs[2])
            ? VmMbstring::coerceOffsetArg($frame, 'mb_stripos', 2)
            : 0;
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_stripos', 3)
            : 'UTF-8';
        $result = VmMbstring::stripos($haystack, $needle, $offset, $encoding);
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
            throw new \LogicException('mb_stripos() requires two to four arguments');
        }
        $folded = JitMbSearch::tryStriposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException('mb_stripos() is not lowered for JIT/AOT in this compiler build');
    }
}
