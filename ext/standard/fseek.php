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

/** fseek() — VM via VmFs; JIT/AOT via __compiler_fseek (issue #1191). */
final class fseek extends Internal
{
    public function __construct()
    {
        parent::__construct('fseek');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fseek() requires two or three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fseek');
        if (null === $frame->returnVar) {
            return;
        }
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'fseek', 2, 'offset');
        $whence = \SEEK_SET;
        if (3 === $argc) {
            $whenceVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $whenceVar->type) {
                throw new \LogicException('fseek() whence must be an integer in this compiler build');
            }
            $whence = $whenceVar->toInt();
        }
        $frame->returnVar->int(VmFs::fseek($handle, $offsetInt, $whence));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fseek() requires two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fseek() handle'),
            $i64
        );
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'fseek', 2, 'offset');
        if (3 === $argc) {
            $whence = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'fseek() whence'),
                $i64
            );
        } else {
            $whence = $i64->constInt(\SEEK_SET, false);
        }

        return JitFseek::invoke($context, $handle, $offset, $whence);
    }
}
