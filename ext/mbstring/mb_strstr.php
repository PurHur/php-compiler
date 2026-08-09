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
 * mb_strstr() — multibyte strstr (php-src ext/mbstring/mbstring.c).
 */
final class mb_strstr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strstr');
    }

    public function execute(Frame $frame): void
    {
        // Sparse named args (encoding: without before_needle) — isset, not count (#28584).
        if (!isset($frame->calledArgs[0]) || !isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError(sprintf(
                'mb_strstr() expects at least 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (isset($frame->calledArgs[4])) {
            throw new \ArgumentCountError(sprintf(
                'mb_strstr() expects at most 4 arguments, %d given',
                max(\array_keys($frame->calledArgs)) + 1
            ));
        }
        // Z_PARAM_STR — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21313).
        $haystack = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strstr', 0, 'haystack');
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_strstr', 1, 'needle');
        $part = isset($frame->calledArgs[2])
            ? VmMbstring::coercePartArg($frame->calledArgs[2], 'mb_strstr', 2)
            : false;
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_strstr', 3)
            : 'UTF-8';
        $result = VmMbstring::strstr($haystack, $needle, $part, $encoding);
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strstr() requires two to four arguments');
        }
        $folded = JitMbSearch::tryStrstrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException('mb_strstr() is not lowered for JIT/AOT in this compiler build');
    }
}
