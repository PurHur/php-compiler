<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_share_errno() — last share error code (php-src ext/curl/share.c; #20531).
 */
final class curl_share_errno extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_share_errno');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_share_errno() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $share = VmCurlArg::requireShareObject($frame->calledArgs[0], 'curl_share_errno', 1);
        $frame->returnVar->int(VmCurlShare::errno($share));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_share_errno() is not implemented for JIT in this compiler build (issue #20531)');
    }
}
