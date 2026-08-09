<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ext\standard\JsonStringOperandArg;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Shared JIT stub for simdjson key_* (#27857). */
abstract class SimdjsonKeyFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #27857)');
    }
}

/** simdjson_key_exists() — PECL JSON-pointer existence (#27857). */
final class simdjson_key_exists extends SimdjsonKeyFunction
{
    public function __construct()
    {
        parent::__construct('simdjson_key_exists');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'simdjson_key_exists', 2);
        $this->requireAtMostArgCount($frame, 'simdjson_key_exists', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $json = JsonStringOperandArg::vmJson($frame, 'simdjson_key_exists');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'simdjson_key_exists', 1, 'key');
        $depth = 512;
        if (isset($frame->calledArgs[2])) {
            $depth = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'simdjson_key_exists', 3, 'depth');
        }
        $frame->returnVar->bool(VmSimdjson::keyExists($json, $key, $depth));
    }
}

/** simdjson_key_count() — PECL JSON-pointer child count (#27857). */
final class simdjson_key_count extends SimdjsonKeyFunction
{
    public function __construct()
    {
        parent::__construct('simdjson_key_count');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'simdjson_key_count', 2);
        $this->requireAtMostArgCount($frame, 'simdjson_key_count', 4);
        if (null === $frame->returnVar) {
            return;
        }
        $json = JsonStringOperandArg::vmJson($frame, 'simdjson_key_count');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'simdjson_key_count', 1, 'key');
        $depth = 512;
        if (isset($frame->calledArgs[2])) {
            $depth = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'simdjson_key_count', 3, 'depth');
        }
        $throwIfUncountable = false;
        if (isset($frame->calledArgs[3])) {
            $throwIfUncountable = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[3],
                'simdjson_key_count',
                4,
                'throw_if_uncountable'
            );
        }
        $frame->returnVar->int(VmSimdjson::keyCount($json, $key, $depth, $throwIfUncountable));
    }
}

/** simdjson_key_value() — PECL JSON-pointer fetch (#27857). */
final class simdjson_key_value extends SimdjsonKeyFunction
{
    public function __construct()
    {
        parent::__construct('simdjson_key_value');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'simdjson_key_value', 2);
        $this->requireAtMostArgCount($frame, 'simdjson_key_value', 4);
        if (null === $frame->returnVar) {
            return;
        }
        $json = JsonStringOperandArg::vmJson($frame, 'simdjson_key_value');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'simdjson_key_value', 1, 'key');
        $associative = false;
        if (isset($frame->calledArgs[2])) {
            $associative = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2],
                'simdjson_key_value',
                3,
                'associative'
            );
        }
        $depth = 512;
        if (isset($frame->calledArgs[3])) {
            $depth = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'simdjson_key_value', 4, 'depth');
        }
        $decoded = VmSimdjson::keyValue($json, $key, $associative, $depth);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('simdjson_key_value() requires VM context in this compiler build');
        }
        $frame->returnVar->copyFrom(VmJson::importDecoded($decoded, $associative, $ctx));
    }
}
