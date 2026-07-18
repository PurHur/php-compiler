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
 * curl_escape() — URL-encode a string (php-src ext/curl/interface.c; #6351, #20493).
 *
 * Signature: curl_escape(CurlHandle $handle, string $string): string|false
 */
final class curl_escape extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_escape');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_escape() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_escape', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'curl_escape',
            1,
            'string'
        );
        $frame->returnVar->string(VmCurlEscape::escape($value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_escape() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }
        // Handle is required for php-src-strict arity/type; encoding matches curl_easy_escape
        // unreserved set (rawurlencode) and does not depend on handle state.
        $str = JitStringBuiltinArg::lower($context, $args[1], 'curl_escape', 1, 'string');

        return JitUrlencode::rawurlencode($context, $str);
    }
}
