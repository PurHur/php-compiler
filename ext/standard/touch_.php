<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
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
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('touch() requires one to three arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('touch() filename must be a string in this compiler build');
        }
        $mtime = null;
        if ($argc >= 2) {
            $mtimeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $mtimeVar->type && Variable::TYPE_INTEGER !== $mtimeVar->type) {
                throw new \LogicException('touch() mtime must be an integer or null in this compiler build');
            }
            if (Variable::TYPE_INTEGER === $mtimeVar->type) {
                $mtime = $mtimeVar->toInt();
            }
        }
        $atime = null;
        if (3 === $argc) {
            $atimeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $atimeVar->type) {
                throw new \LogicException('touch() atime must be an integer in this compiler build');
            }
            $atime = $atimeVar->toInt();
        }
        $frame->returnVar->bool(VmFs::touch($pathVar->toString(), $mtime, $atime));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('touch() requires one to three arguments in this compiler build');
        }
        $path = $this->jitString($context, $args[0], 'touch() argument #1');
        $i64 = $context->getTypeFromString('int64');
        $sentinel = $i64->constInt(-1, true);
        $mtime = $sentinel;
        if ($argc >= 2) {
            $mtimeArg = $args[1];
            if (Variable::TYPE_NULL === $mtimeArg->type) {
                $mtime = $sentinel;
            } else {
                $mtime = JitLongArg::lower($context, $mtimeArg, 'touch() argument #2');
            }
        }
        $atime = $sentinel;
        if (3 === $argc) {
            $atime = JitLongArg::lower($context, $args[2], 'touch() argument #3');
        }

        return JitTouch::invoke($context, $path, $mtime, $atime);
    }
}
