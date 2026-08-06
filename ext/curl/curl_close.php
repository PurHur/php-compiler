<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_close() — release easy handle (php-src ext/curl/interface.c; #6322).
 *
 * PHP 8.5+ emits E_DEPRECATED (curl.stub.php #[\Deprecated]; #28133).
 */
final class curl_close extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_close');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_close() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_close', 1);
        // After Z_PARAM_OBJECT — stub #[\Deprecated] side-effect (curl.c / curl.stub.php; #28133).
        CurlCloseDeprecation::emitClose($frame);
        VmCurlEasy::close($easy);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_close() is not implemented for JIT in this compiler build (issue #6322)');
    }
}
