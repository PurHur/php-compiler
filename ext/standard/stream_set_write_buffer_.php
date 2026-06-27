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

/** stream_set_write_buffer() — VM via VmFs; JIT/AOT via __compiler_stream_set_write_buffer (issue #3755). */
final class stream_set_write_buffer_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_set_write_buffer');
    }

    public function execute(Frame $frame): void
    {
        self::run($frame, $this->getName());
    }

    public static function run(Frame $frame, string $functionName): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException($functionName.'() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $bufferVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $bufferVar->type) {
            throw new \TypeError($functionName.'(): Argument #2 ($buffer) must be of type int');
        }
        $handle = VmStreamArg::requireStreamHandle($handleVar, $functionName);
        if (null === $frame->returnVar) {
            return;
        }
        $previous = VmFs::streamSetWriteBuffer($handle, $bufferVar->toInt());
        if (false === $previous) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($previous);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return self::callJit($context, $this->getName(), ...$args);
    }

    public static function callJit(Context $context, string $functionName, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException($functionName.'() requires exactly two arguments in this compiler build');
        }

        return JitStreamSetWriteBuffer::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], $functionName.'() stream'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], $functionName.'() buffer'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
