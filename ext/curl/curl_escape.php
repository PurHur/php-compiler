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
 * curl_escape() — URL-encode a string (php-src ext/curl/interface.c; #6351).
 */
final class curl_escape extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_escape');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_escape() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'curl_escape',
            0,
            'string'
        );
        $frame->returnVar->string(VmCurlEscape::escape($value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_escape() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }
        $str = JitStringBuiltinArg::lower($context, $args[0], 'curl_escape', 0, 'string');

        return JitUrlencode::rawurlencode($context, $str);
    }
}
