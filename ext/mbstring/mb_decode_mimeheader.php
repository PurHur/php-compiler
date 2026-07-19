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
 * mb_decode_mimeheader() — decode RFC 2047 encoded words (php-src ext/mbstring/mbstring.c; #6038).
 */
final class mb_decode_mimeheader extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_decode_mimeheader');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mb_decode_mimeheader() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $str = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'mb_decode_mimeheader',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmMbstring::decodeMimeheader($str));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $folded = JitMbMimeheader::tryDecodeCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException(
            'mb_decode_mimeheader() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
