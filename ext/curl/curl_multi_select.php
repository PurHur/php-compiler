<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * curl_multi_select() — wait on multi sockets (php-src ext/curl/multi.c; #3721).
 */
final class curl_multi_select extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_select');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                $argc < 1
                    ? 'curl_multi_select() expects at least 1 argument, %d given'
                    : 'curl_multi_select() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_select', 1);
        $timeout = 1.0;
        if (isset($frame->calledArgs[1])) {
            $tv = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_DOUBLE === $tv->type) {
                $timeout = $tv->toFloat();
            } else {
                $timeout = (float) VmMath::parseIntBuiltinArg($tv, 'curl_multi_select', 1, 'timeout');
            }
        }
        $n = VmCurlMulti::select($multi, $timeout);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($n);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_select() is not implemented for JIT in this compiler build (issue #3721)');
    }
}
