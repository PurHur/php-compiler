<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_upkeep() — connection upkeep (php-src ext/curl/interface.c; #20977).
 *
 * Signature: curl_upkeep(CurlHandle $handle): bool
 * Returns true iff curl_easy_upkeep() returns CURLE_OK (SAVE_CURL_ERROR on handle).
 * Reflection / named `$handle` via BuiltinInternalArgInfo + BuiltinParamNames (#27702).
 */
final class curl_upkeep extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_upkeep');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_upkeep() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_upkeep', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmCurlEasy::upkeep($easy));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_upkeep() is not implemented for JIT in this compiler build (issue #20977)');
    }
}
