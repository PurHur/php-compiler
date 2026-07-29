<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * stream_set_blocking() — toggle blocking mode on stream resources (issue #6007).
 *
 * Also registered as socket_set_blocking() via PHP_FALIAS (issue #20903).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_set_blocking)
 * php-src: ext/standard/basic_functions.stub.php — @alias stream_set_blocking
 */
final class stream_set_blocking extends Internal
{
    public function __construct(string $name = 'stream_set_blocking')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException($fn.'() requires exactly two arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            $fn,
            1
        );
        if (InternalStrictArg::isCallerStrict($frame)) {
            $mode = InternalStrictArg::requireBool($frame, 1, $fn, 'enable')->toBool();
        } else {
            $mode = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                $fn,
                2,
                'enable'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::streamSetBlocking($handle, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (2 !== \count($args)) {
            throw new \LogicException($fn.'() requires exactly two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        JitInternalStrictArg::requireBool($context, $args[1], $fn, 'enable', 2);
        $mode = $context->builder->zExt(
            JitBoolArg::lower($context, $args[1], $fn.'(): Argument #2 ($enable)'),
            $i64
        );

        return JitStreamSetBlocking::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], $fn.'() stream'),
                $i64
            ),
            $mode
        );
    }
}
