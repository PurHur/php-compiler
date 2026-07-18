<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_share_init_persistent() — process-lifetime share handle (php-src ext/curl/share.c; #20530).
 *
 * PHP 8.5+: curl_share_init_persistent(array $share_options): CurlSharePersistentHandle
 */
final class curl_share_init_persistent extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_share_init_persistent');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_share_init_persistent() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        [$sorted, $persistentId] = VmCurlShare::parsePersistentShareOptions($frame->calledArgs[0]);
        $var = VmCurlShare::initPersistent($frame->vmContext, $sorted, $persistentId);
        $frame->returnVar->object($var->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_share_init_persistent() is not implemented for JIT in this compiler build (issue #20530)');
    }
}
