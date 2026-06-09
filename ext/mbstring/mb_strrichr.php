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
 * mb_strrichr() — case-insensitive multibyte strchr (php-src ext/mbstring/mbstring.c; #7015).
 */
final class mb_strrichr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strrichr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_strrichr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $haystack = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_strrichr',
            0,
            'haystack'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_strrichr',
            1,
            'needle'
        );
        $part = $argc >= 3
            ? VmMbstring::coercePartArg($frame->calledArgs[2], 'mb_strrichr', 2)
            : false;
        $encoding = $argc >= 4
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_strrichr', 3)
            : 'UTF-8';
        $result = VmMbstring::strrichr($haystack, $needle, $part, $encoding);
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
            throw new \LogicException('mb_strrichr() requires two to four arguments');
        }
        $folded = JitMbSearch::tryStrrichrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException('mb_strrichr() is not lowered for JIT/AOT in this compiler build');
    }
}
