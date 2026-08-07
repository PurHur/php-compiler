<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_last() — last element value (php-src array.c, #3491).
 *
 * Excess argc → ArgumentCountError (#28682; peer #28679 / #28691).
 */
final class array_last extends Internal
{
    public function __construct()
    {
        parent::__construct('array_last');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.c — ArgumentCountError (#28682).
        $this->requireExactArgCount($frame, 'array_last', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_last');
        $value = VmArray::valueLast($ht);
        if (null === $value) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($value);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28682.
        if (!$this->requireExactJitArgCount($context, $args, 'array_last', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitArrayElem::last($context, $args[0]);
    }
}
