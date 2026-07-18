<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\JitUrlencode;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_unescape() — URL-decode a string (php-src ext/curl/interface.c; #6351, #20493).
 *
 * Signature: curl_unescape(CurlHandle $handle, string $string): string|false
 */
final class curl_unescape extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_unescape');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_unescape() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_unescape', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'curl_unescape',
            1,
            'string'
        );
        $frame->returnVar->string(VmCurlEscape::unescape($value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_unescape() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }
        $str = JitStringBuiltinArg::lower($context, $args[1], 'curl_unescape', 1, 'string');

        return JitUrlencode::rawurldecode($context, $str);
    }
}
