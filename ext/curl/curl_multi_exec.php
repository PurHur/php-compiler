<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_multi_exec() — drive multi transfers (php-src ext/curl/multi.c; #3721).
 *
 * Argument #2 ($still_running) is by-ref via {@see \PHPCompiler\BuiltinByRefParams}.
 */
final class curl_multi_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_exec');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_exec() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_exec', 1);
        // php-src Z_PARAM_ZVAL + ZEND_TRY_ASSIGN_REF_LONG — input value ignored (null/string ok).
        $stillVar = $frame->calledArgs[1];
        [$rc, $running] = VmCurlMulti::exec($multi, 0);
        $stillVar->resolveIndirect()->int($running);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($rc);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_exec() is not implemented for JIT in this compiler build (issue #3721)');
    }
}
