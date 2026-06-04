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

/** time_nanosleep() — sub-second sleep (VM host; JIT/AOT via nanosleep, issue #5180). */
final class time_nanosleep extends Internal
{
    public function __construct()
    {
        parent::__construct('time_nanosleep');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'time_nanosleep() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $seconds = self::requireInt($frame->calledArgs[0], 1);
        $nanoseconds = self::requireInt($frame->calledArgs[1], 2);
        $result = VmSleep::timeNanosleep($seconds, $nanoseconds);
        if (\is_array($result)) {
            $frame->returnVar->copyFrom(VmJson::import($result));
        } elseif (\is_bool($result)) {
            $frame->returnVar->bool($result);
        } else {
            $frame->returnVar->bool(false);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'time_nanosleep() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitSleep::timeNanosleep(
            $context,
            JitLongArg::lower($context, $args[0], 'time_nanosleep() seconds'),
            JitLongArg::lower($context, $args[1], 'time_nanosleep() nanoseconds')
        );
    }

    private static function requireInt(Variable $arg, int $position): int
    {
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \TypeError(
                \sprintf(
                    'time_nanosleep(): Argument #%d ($%s) must be of type int, %s given',
                    $position,
                    1 === $position ? 'seconds' : 'nanoseconds',
                    self::zendTypeName($v)
                )
            );
        }

        return $v->toInt();
    }

    private static function zendTypeName(Variable $v): string
    {
        return match ($v->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'resource',
        };
    }
}
