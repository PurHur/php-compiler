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
 * iconv_mime_decode_headers() — decode RFC 822 header blocks (php-src ext/iconv/iconv.c; #19448).
 */
final class iconv_mime_decode_headers extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv_mime_decode_headers');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_mime_decode_headers() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $headers = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'iconv_mime_decode_headers',
            0,
            'headers'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $mode = 0;
        if ($argc >= 2) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'iconv_mime_decode_headers', 2, 'mode');
        }
        $charset = null;
        if ($argc >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $charset = VmIconv::coerceEncodingArg(
                    $frame->calledArgs[2],
                    'iconv_mime_decode_headers',
                    2,
                    'encoding'
                );
            }
        }
        $result = VmIconvMime::mimeDecodeHeaders($headers, $mode, $charset, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->array(VmIconvMime::headersResultToHashTable($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIconvMime::invokeDecodeHeaders($context, ...$args);
    }
}
