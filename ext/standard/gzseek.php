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

/** gzseek() — seek within gzip stream (ext/zlib/zlib.c, #14585). */
final class gzseek extends Internal
{
    public function __construct()
    {
        parent::__construct('gzseek');
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() expects two or three arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $offset = VmMath::parseIntBuiltinArgForFrame($frame, 1, $fn, 2, 'offset');
        $whence = \SEEK_SET;
        if (3 === $argc) {
            $whenceVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $whenceVar->type) {
                throw new \LogicException($fn.'() whence must be an integer in this compiler build');
            }
            $whence = $whenceVar->toInt();
        }
        $frame->returnVar->int(VmGzStream::gzseek($handle, $offset, $whence));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() expects two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() stream'),
            $i64
        );
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], $fn, 2, 'offset');
        if (3 === $argc) {
            $whence = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], $fn.'() whence'),
                $i64
            );
        } else {
            $whence = $i64->constInt(\SEEK_SET, false);
        }

        return JitGzseek::invoke($context, $handle, $offset, $whence);
    }
}
