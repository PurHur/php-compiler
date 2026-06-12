<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzread() — read from gzip stream (ext/zlib/zlib.c parity, #6168). */
final class gzread extends Internal
{
    public function __construct()
    {
        parent::__construct('gzread');
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
        $length = 8192;
        if (2 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                $fn,
                2,
                'length'
            );
        }
        $data = VmGzStream::gzread($handle, $length);
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
        $length = $i64->constInt(8192, false);
        if (2 === $argc) {
            $length = JitIntdiv::lowerIntBuiltinArg($context, $args[1], $fn, 2, 'length');
        }

        return JitGzread::invoke($context, $handle, $length);
    }
}
