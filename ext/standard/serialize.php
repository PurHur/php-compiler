<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** serialize() — VM via VmSerialize; JIT/AOT via __compiler_serialize_value (issues #1174). */
final class serialize extends Internal
{
    public function __construct()
    {
        parent::__construct('serialize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('serialize() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $encoded = VmSerialize::serialize($frame->calledArgs[0]);
        $frame->returnVar->string($encoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('serialize() requires exactly one argument in this compiler build');
        }

        return JitSerialize::encode($context, $args[0]);
    }
}
