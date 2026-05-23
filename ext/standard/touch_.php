<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** touch() — VM via VmFs; JIT/AOT via __compiler_touch (libc utime). */
final class touch_ extends Internal
{
    public function __construct()
    {
        parent::__construct('touch');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('touch() requires one or two arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('touch() filename must be a string in this compiler build');
        }
        $mtime = null;
        if (2 === $argc) {
            $mtimeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $mtimeVar->type) {
                throw new \LogicException('touch() mtime must be an integer in this compiler build');
            }
            $mtime = $mtimeVar->toInt();
        }
        $frame->returnVar->bool(VmFs::touch($pathVar->toString(), $mtime));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('touch() requires exactly two arguments in this compiler build');
        }
        $a = $this->jitString($context, $args[0], 'touch() argument #1');
        $b = $this->jitString($context, $args[1], 'touch() argument #2');

        return JitTouch::invoke($context, $a, $b);
    }
}
