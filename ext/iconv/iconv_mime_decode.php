<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

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
 * iconv_mime_decode() — decode RFC 2047 header fragments (php-src ext/iconv/iconv.c; #6364).
 */
final class iconv_mime_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv_mime_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_mime_decode() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $encoded = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'iconv_mime_decode',
            0,
            'encoded_string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $mode = 0;
        if ($argc >= 2) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'iconv_mime_decode', 2, 'mode');
        }
        $charset = null;
        if ($argc >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $charset = VmIconv::coerceEncodingArg($frame->calledArgs[2], 'iconv_mime_decode', 2, 'charset');
            }
        }
        $result = VmIconvMime::mimeDecode($encoded, $mode, $charset, $frame);
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
        return JitIconvMime::invoke($context, ...$args);
    }
}
