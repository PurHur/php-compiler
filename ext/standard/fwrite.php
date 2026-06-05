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

/** fwrite() / fputs() — VM via VmFs; JIT/AOT via __compiler_fwrite (issue #1070, #6162). */
final class fwrite extends Internal
{
    public function __construct(string $name = 'fwrite')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() requires two or three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, $fn);
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $dataVar->type) {
            throw new \LogicException($fn.'() data must be a string in this compiler build');
        }
        $length = null;
        if (3 === $argc) {
            $lenVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException($fn.'() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        $written = VmFs::fwrite($handle, $dataVar->toString(), $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() requires two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() handle'),
            $i64
        );
        $dataStr = $this->jitString($context, $args[1], $fn.'() data');
        if (3 === $argc) {
            $length = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], $fn.'() length'),
                $i64
            );
        } else {
            $length = $i64->constInt(-1, true);
        }

        return JitFwrite::invoke($context, $handle, $dataStr, $length);
    }
}
