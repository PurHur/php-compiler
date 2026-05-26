<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * krsort() — sort by key descending, preserving values (subset of PHP; issue #2282).
 *
 * VM: homogeneous string or integer keys; packed lists are no-op.
 * JIT/AOT: packed list no-op; string-key hashtable via __hashtable__sortStringKeysReverse.
 */
final class krsort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('krsort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('krsort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('krsort() argument must be an array in this compiler build');
        }
        $array->array(VmArray::krsortCopy($array->toArray()));
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('krsort() requires exactly one argument');
        }
        ArrayBuiltinHelper::krsortByKey($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
