<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_copy_handle() — duplicate easy handle (php-src ext/curl/interface.c; #20495).
 *
 * Signature: curl_copy_handle(CurlHandle $handle): CurlHandle|false
 */
final class curl_copy_handle extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_copy_handle');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_copy_handle() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_copy_handle', 1);
        $copy = VmCurlEasy::copyHandle($easy, $frame->vmContext);
        if (null === $copy) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($copy->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_copy_handle() is not implemented for JIT in this compiler build (issue #20495)');
    }
}
