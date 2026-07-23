<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ext\standard\JsonStringOperandArg;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * simdjson_decode() — PECL simdjson decode MVP (awesomized/simdjson_php; #22530).
 *
 * VM: {@see VmSimdjson::decode} via in-tree JSON parser. JIT not lowered yet.
 */
final class simdjson_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('simdjson_decode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'simdjson_decode', 1);
        $this->requireAtMostArgCount($frame, 'simdjson_decode', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $json = JsonStringOperandArg::vmJson($frame, 'simdjson_decode');
        $associative = false;
        if (isset($frame->calledArgs[1])) {
            $associative = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'simdjson_decode',
                2,
                'associative'
            );
        }
        $depth = 512;
        if (isset($frame->calledArgs[2])) {
            $depth = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                2,
                'simdjson_decode',
                3,
                'depth'
            );
        }
        try {
            $decoded = VmSimdjson::decode($json, $associative, $depth);
        } catch (SimdJsonException $e) {
            throw $e;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('simdjson_decode() requires VM context in this compiler build');
        }
        $frame->returnVar->copyFrom(VmJson::importDecoded($decoded, $associative, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('simdjson_decode() is not implemented for JIT in this compiler build (issue #22530)');
    }
}
