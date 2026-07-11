<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** stream_get_line() — VM via VmFs; JIT/AOT via __compiler_stream_get_line (issue #3738). */
final class stream_get_line extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_get_line');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'stream_get_line() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'stream_get_line() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $maxLenVar = $frame->calledArgs[1]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'stream_get_line');
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $maxLenVar->type) {
            throw new \LogicException('stream_get_line() maxlen must be an integer in this compiler build');
        }
        $maxLength = $maxLenVar->toInt();
        if ($maxLength < 0) {
            $frame->vmContext->errors->triggerError(
                'stream_get_line(): The maximum allowed length must be greater than or equal to zero',
                \E_USER_WARNING,
            );
            $frame->returnVar->bool(false);

            return;
        }
        $ending = null;
        if (3 === $argc) {
            $ending = VmReflection::stringArg($frame->calledArgs[2], 'stream_get_line() ending', 2);
        }
        $line = VmFs::streamGetLine($handle, $maxLength, $ending);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'stream_get_line() expects at least 2 arguments, '.$argc.' given'
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        if ($argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'stream_get_line() expects at most 3 arguments, '.$argc.' given'
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_get_line() stream'),
            $i64
        );
        $maxLength = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'stream_get_line() maxlen'),
            $i64
        );
        $ending = $strPtr->constNull();
        if (3 === $argc) {
            $ending = JitStringArg::lower($context, $args[2], 'stream_get_line() ending');
        }

        return JitStreamGetLine::invoke($context, $handle, $maxLength, $ending);
    }
}
