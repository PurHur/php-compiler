<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_set_timeout() — VM via VmFs; JIT/AOT via __compiler_stream_set_timeout (issue #3754).
 *
 * Also registered as socket_set_timeout() via PHP_FALIAS (issue #20903).
 * Z_PARAM_LONG null under strict_types → TypeError (#31263).
 *
 * php-src: ext/standard/basic_functions.stub.php — @alias stream_set_timeout
 */
final class stream_set_timeout_ extends Internal
{
    public function __construct(string $name = 'stream_set_timeout')
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
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            $fn,
            1
        );
        // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31263).
        $seconds = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            $fn,
            2,
            'seconds'
        );
        $microseconds = 0;
        if (3 === $argc) {
            $microseconds = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                $fn,
                3,
                'microseconds'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::streamSetTimeout($handle, $seconds, $microseconds));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() requires two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        // Early return after compile-time null TypeError (AOT verify; peer scandir #31244).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitSleep::zParamLong($context, $args[1], $fn, 2, 'seconds');
            BasicBlockHelper::ensureOpenInsertBlock($context, $fn.'_null_seconds_te_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (3 === $argc && $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            JitSleep::zParamLong($context, $args[2], $fn, 3, 'microseconds');
            BasicBlockHelper::ensureOpenInsertBlock($context, $fn.'_null_microseconds_te_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $usec = 3 === $argc
            ? JitSleep::zParamLong($context, $args[2], $fn, 3, 'microseconds')
            : $i64->constInt(0, false);

        return JitStreamSetTimeout::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], $fn.'() stream'),
                $i64
            ),
            JitSleep::zParamLong($context, $args[1], $fn, 2, 'seconds'),
            $usec
        );
    }
}
