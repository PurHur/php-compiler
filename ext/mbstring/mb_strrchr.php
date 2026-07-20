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
 * mb_strrchr() — reverse multibyte strchr (php-src ext/mbstring/mbstring.c; #20006).
 */
final class mb_strrchr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strrchr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_strrchr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $haystack = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strrchr', 0, 'haystack');
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_strrchr', 1, 'needle');
        $part = $argc >= 3
            ? VmMbstring::coercePartArg($frame->calledArgs[2], 'mb_strrchr', 2)
            : false;
        $encoding = $argc >= 4
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_strrchr', 3)
            : 'UTF-8';
        $result = VmMbstring::strrchr($haystack, $needle, $part, $encoding);
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
            throw new \LogicException('mb_strrchr() requires two to four arguments');
        }
        $folded = JitMbSearch::tryStrrchrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException('mb_strrchr() is not lowered for JIT/AOT in this compiler build');
    }
}
