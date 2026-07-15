<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * curl_init() — minimal easy handle for share attachment (php-src ext/curl/interface.c; #6322).
 */
final class curl_init extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'curl_init() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $url = null;
        if (isset($frame->calledArgs[0])) {
            $url = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'curl_init', 0, 'url');
        }
        $var = VmCurlEasy::init($url, $frame->vmContext);
        $frame->returnVar->object($var->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_init() is not implemented for JIT in this compiler build (issue #6322)');
    }
}
