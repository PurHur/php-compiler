<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_decode_numericentity() — HTML numeric entity decoder (php-src ext/mbstring/mbstring.c; #7237).
 */
final class mb_decode_numericentity extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_decode_numericentity');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_decode_numericentity() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_decode_numericentity',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $convmap = VmMbstring::coerceConvMapArg($frame->calledArgs[1], 'mb_decode_numericentity');
        $encoding = $argc >= 3
            ? VmMbstring::coerceEncodingString($frame->calledArgs[2], 'mb_decode_numericentity', 2)
            : 'UTF-8';
        $encoding = VmMbstring::resolveNumericEntityEncoding($encoding, 'mb_decode_numericentity', 2);
        $frame->returnVar->string(
            VmMbstring::decodeNumericEntity($str, $convmap, $encoding)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbNumericEntity::invokeDecodeRuntime($context, $args);
    }
}
