<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_share_close() — release share handle (php-src ext/curl/interface.c; #6322).
 */
final class curl_share_close extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_share_close');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_share_close() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        $share = VmCurlArg::requireShareObject($frame->calledArgs[0], 'curl_share_close', 1);
        VmCurlShare::close($share);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_share_close() is not implemented for JIT in this compiler build (issue #6322)');
    }
}
