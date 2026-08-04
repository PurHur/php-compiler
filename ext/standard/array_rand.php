<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayRandRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPLLVM\Value;

/**
 * array_rand() — random key(s) from an array (issue #2321, #4460).
 *
 * VM: returns actual keys (string or int); MT19937 via {@see VmMt19937} (php-src php_array_pick_keys).
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\ArrayRandRuntime::call()} via {@see \PHPCompiler\JIT\ArrayRandLlvm}.
 */
final class array_rand extends Internal
{
    public function __construct()
    {
        parent::__construct('array_rand');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() accepts one or two arguments');
        }
        $array = VmArray::requireArrayParam($frame->calledArgs[0], 'array_rand', 1, 'array');
        $num = 1;
        if (2 === $argc) {
            $num = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_rand', 2, 'num');
        }
        $result = VmArray::arrayRandPacked($array, $num);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return ArrayRandRuntime::call($context, ...$args);
    }
}
