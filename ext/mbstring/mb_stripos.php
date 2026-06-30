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
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_stripos() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $haystack = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_stripos',
            0,
            'haystack'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_stripos',
            1,
            'needle'
        );
        $offset = $argc >= 3
            ? VmMbstring::coerceOffsetArg($frame, 'mb_stripos', 2)
            : 0;
        $encoding = $argc >= 4
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
