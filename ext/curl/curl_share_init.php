<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_share_init() — allocate share handle (php-src ext/curl/interface.c; #6322).
 */
final class curl_share_init extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_share_init');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_share_init() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $var = VmCurlShare::init($frame->vmContext);
        $frame->returnVar->object($var->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_share_init() is not implemented for JIT in this compiler build (issue #6322)');
    }
}
