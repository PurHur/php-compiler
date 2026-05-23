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
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('touch() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('touch() filename must be a string in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $mtime = $i64->constInt(-1, true);
        if (2 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('touch() mtime must be an integer in this compiler build');
            }
            $mtime = $context->helper->loadValue($args[1]);
        }

        return JitTouch::invoke($context, $context->helper->loadValue($args[0]), $mtime);
    }
}
