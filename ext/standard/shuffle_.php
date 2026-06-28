<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ShuffleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * shuffle() — randomize array values in place (issue #2310, #4460).
 *
 * VM: Fisher–Yates via CSPRNG; associative arrays reindex to 0..n-1.
 * JIT/AOT: {@see ShuffleRuntime::shufflePacked()}.
 */
final class shuffle_ extends Internal
{
    public function __construct()
    {
        parent::__construct('shuffle');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('shuffle() requires exactly one argument');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0], 'shuffle');
        VmArray::shufflePacked($ht);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('shuffle() requires exactly one argument');
        }
        JitArrayElem::requireArrayArg($context, $args[0], 'shuffle');
        ShuffleRuntime::shufflePacked($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
