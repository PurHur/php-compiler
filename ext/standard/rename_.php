<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rename() — VM via VmFs; JIT/AOT via libc rename(2). */
final class rename_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rename');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('rename() requires exactly two arguments in this compiler build');
        }
        $fromVar = $frame->calledArgs[0]->resolveIndirect();
        $toVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $fromVar->type || Variable::TYPE_STRING !== $toVar->type) {
            throw new \LogicException('rename() requires string paths in this compiler build');
        }
        $frame->returnVar->bool(VmFs::rename($fromVar->toString(), $toVar->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('rename() requires exactly two arguments in this compiler build');
        }
        $a = $this->jitString($context, $args[0], 'rename() argument #1');
        $b = $this->jitString($context, $args[1], 'rename() argument #2');

        return JitRename::invoke($context, $a, $b);
    }
}
