<?php

declare(strict_types=1);

namespace PHPCompiler\Func;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Handler;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context as JITContext;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Context;
use PHPLLVM\Value;

abstract class Internal extends Func implements Handler, Call
{
    public function __construct(string $name = null)
    {
        if (null === $name) {
            $parts = explode('\\', get_class($this));
            $name = end($parts);
        }
        parent::__construct($name);
    }

    public function getFrame(Context $context, ?Frame $frame = null): Frame
    {
        return new Frame($this, null, null);
    }

    protected function jitString(JITContext $context, JITVariable $arg, string $contextLabel = 'argument'): Value
    {
        return JitStringArg::lower($context, $arg, $contextLabel);
    }

    protected function jitLong(JITContext $context, JITVariable $arg, string $contextLabel = 'argument'): Value
    {
        return JitLongArg::lower($context, $arg, $contextLabel);
    }

    protected function jitBool(JITContext $context, JITVariable $arg, string $contextLabel = 'argument'): Value
    {
        return JitBoolArg::lower($context, $arg, $contextLabel);
    }

    protected function requireStringArgs(JITContext $context, array $args, int $n, string $contextLabel = 'argument'): void
    {
        for ($i = 0; $i < $n; ++$i) {
            if (!isset($args[$i])) {
                throw new \LogicException("{$contextLabel} requires at least {$n} argument(s)");
            }
            $arg = $args[$i];
            if (null !== JitStringArg::compileTimeLiteral($arg)) {
                continue;
            }
            if (\in_array($arg->type, [
                JITVariable::TYPE_STRING,
                JITVariable::TYPE_VALUE,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::TYPE_NATIVE_DOUBLE,
                JITVariable::TYPE_NATIVE_BOOL,
            ], true)) {
                continue;
            }
            throw new \LogicException("{$contextLabel} argument #".($i + 1).' must be a string in this compiler build');
        }
    }

    protected function stringDataPtr(JITContext $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->structGep($strPtr, $off);
    }

    /**
     * Arity guard for VM builtin execute() — Zend ArgumentCountError (#4145).
     */
    protected function requireExactArgCount(Frame $frame, string $function, int $expected): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc !== $expected) {
            throw new \ArgumentCountError(self::exactArgCountMessage($function, $expected, $argc));
        }
    }

    protected function requireAtLeastArgCount(Frame $frame, string $function, int $minimum): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < $minimum) {
            throw new \ArgumentCountError(self::atLeastArgCountMessage($function, $minimum, $argc));
        }
    }

    protected function requireAtMostArgCount(Frame $frame, string $function, int $maximum): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > $maximum) {
            throw new \ArgumentCountError(self::atMostArgCountMessage($function, $maximum, $argc));
        }
    }

    protected function requireArgCountRange(Frame $frame, string $function, int $minimum, int $maximum): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < $minimum) {
            throw new \ArgumentCountError(self::atLeastArgCountMessage($function, $minimum, $argc));
        }
        if ($argc > $maximum) {
            throw new \ArgumentCountError(self::atMostArgCountMessage($function, $maximum, $argc));
        }
    }

    /**
     * Arity guard for JIT/AOT builtin lowering (#4145).
     *
     * Emits a pending ArgumentCountError in LLVM IR (AOT-safe) instead of throwing
     * during compile-time lowering. Returns false when argc is wrong.
     *
     * @param JITVariable[] $args
     */
    protected function requireExactJitArgCount(JITContext $context, array $args, string $function, int $expected): bool
    {
        $argc = \count($args);
        if ($argc !== $expected) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                self::exactArgCountMessage($function, $expected, $argc)
            );

            return false;
        }

        return true;
    }

    /**
     * @param JITVariable[] $args
     */
    protected function requireAtLeastJitArgCount(JITContext $context, array $args, string $function, int $minimum): bool
    {
        $argc = \count($args);
        if ($argc < $minimum) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                self::atLeastArgCountMessage($function, $minimum, $argc)
            );

            return false;
        }

        return true;
    }

    /**
     * @param JITVariable[] $args
     */
    protected function requireAtMostJitArgCount(JITContext $context, array $args, string $function, int $maximum): bool
    {
        $argc = \count($args);
        if ($argc > $maximum) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                self::atMostArgCountMessage($function, $maximum, $argc)
            );

            return false;
        }

        return true;
    }

    /**
     * @param JITVariable[] $args
     */
    protected function requireArgCountRangeJit(JITContext $context, array $args, string $function, int $minimum, int $maximum): bool
    {
        $argc = \count($args);
        if ($argc < $minimum) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                self::atLeastArgCountMessage($function, $minimum, $argc)
            );

            return false;
        }
        if ($argc > $maximum) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                self::atMostArgCountMessage($function, $maximum, $argc)
            );

            return false;
        }

        return true;
    }

    private static function exactArgCountMessage(string $function, int $expected, int $given): string
    {
        return \sprintf(
            '%s() expects exactly %d argument%s, %d given',
            $function,
            $expected,
            1 === $expected ? '' : 's',
            $given
        );
    }

    private static function atLeastArgCountMessage(string $function, int $minimum, int $given): string
    {
        return \sprintf(
            '%s() expects at least %d argument%s, %d given',
            $function,
            $minimum,
            1 === $minimum ? '' : 's',
            $given
        );
    }

    private static function atMostArgCountMessage(string $function, int $maximum, int $given): string
    {
        return \sprintf(
            '%s() expects at most %d argument%s, %d given',
            $function,
            $maximum,
            1 === $maximum ? '' : 's',
            $given
        );
    }
}
