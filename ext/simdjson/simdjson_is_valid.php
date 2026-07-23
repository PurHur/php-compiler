<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ext\standard\JsonStringOperandArg;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * simdjson_is_valid() — PECL simdjson validity check MVP (awesomized/simdjson_php; #22530).
 *
 * VM: {@see VmSimdjson::isValid} via {@see \PHPCompiler\ext\standard\VmJsonScanner}.
 */
final class simdjson_is_valid extends Internal
{
    public function __construct()
    {
        parent::__construct('simdjson_is_valid');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'simdjson_is_valid', 1);
        $this->requireAtMostArgCount($frame, 'simdjson_is_valid', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $json = JsonStringOperandArg::vmJson($frame, 'simdjson_is_valid');
        $depth = 512;
        if (isset($frame->calledArgs[1])) {
            $depth = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                1,
                'simdjson_is_valid',
                2,
                'depth'
            );
        }
        $frame->returnVar->bool(VmSimdjson::isValid($json, $depth));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('simdjson_is_valid() is not implemented for JIT in this compiler build (issue #22530)');
    }
}
