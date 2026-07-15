<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_substr() — multibyte substring (php-src ext/mbstring/mbstring.c; #3239).
 *
 * PHP 8.4+ optional $truncate silences Z_STR_TRUNCATED warnings (#17239).
 */
final class mb_substr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $supportsTruncate = CompilerVersion::supportsSubstrTruncate();
        $maxArgs = $supportsTruncate ? 5 : 4;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'mb_substr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > $maxArgs) {
            throw new \ArgumentCountError(\sprintf(
                'mb_substr() expects at most %d arguments, %d given',
                $maxArgs,
                $argc
            ));
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19297, mbstring.c).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'mb_substr', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMbstring::coerceStartArg($frame, 'mb_substr', 1);
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $length = VmMbstring::coerceOptionalLengthArg($frame, 'mb_substr', 2);
        }
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_substr', 3)
            : 'UTF-8';
        $truncate = false;
        if ($supportsTruncate && isset($frame->calledArgs[4])) {
            $truncate = \PHPCompiler\ext\standard\VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                4,
                'mb_substr',
                5,
                'truncate'
            );
        }
        $warnOnClip = $supportsTruncate && !$truncate;
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::substr($string, $start, $length, $encoding, $warnOnClip, $frame)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_substr() is not lowered for JIT/AOT in this compiler build');
    }
}
