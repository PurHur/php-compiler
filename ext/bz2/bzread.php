<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** bzread() — read from bzip2 stream (ext/bz2/bz2.c parity, #17301). */
final class bzread extends Internal
{
    public function __construct()
    {
        parent::__construct('bzread');
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($fn.'() expects one or two arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $length = 1024;
        if (2 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                $fn,
                2,
                'length'
            );
        }
        $data = VmBz2Stream::bzread($handle, $length);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($fn.'() expects one or two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() stream'),
            $i64
        );
        $length = $i64->constInt(1024, false);
        if (2 === $argc) {
            $length = \PHPCompiler\ext\standard\JitIntdiv::lowerIntBuiltinArg($context, $args[1], $fn, 2, 'length');
        }

        return JitBz2read::invoke($context, $handle, $length);
    }
}
