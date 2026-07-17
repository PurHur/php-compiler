<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_detect_encoding() — guess byte-string encoding (php-src ext/mbstring/mbstring.c; #3075).
 */
final class mb_detect_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_detect_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_detect_encoding() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20225, mbstring.c).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'mb_detect_encoding', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encodingList = null;
        if ($argc >= 2) {
            $encodingList = VmMbstring::coerceDetectEncodingListArg(
                $frame->calledArgs[1],
                'mb_detect_encoding',
                1
            );
        }
        $strict = false;
        if (3 === $argc) {
            $strict = VmMath::parseBoolBuiltinArg($frame->calledArgs[2], 'mb_detect_encoding', 3, 'strict');
        }
        $result = VmMbstring::detectEncoding($string, $encodingList, $strict);
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
        throw new \LogicException(
            'mb_detect_encoding() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
