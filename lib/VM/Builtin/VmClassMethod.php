<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** VM-only class method handler; JIT call() is deferred (#1366). */
abstract class VmClassMethod extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            static::class.' is not implemented for JIT in this compiler build'
        );
    }

    /** User arity excludes $this (php-src ZEND_NUM_ARGS). */
    protected function userArgCount(Frame $frame): int
    {
        return max(0, \count($frame->calledArgs) - 1);
    }

    /**
     * Exact user arity → Zend ArgumentCountError (#30834; php-src stubs).
     *
     * $function is Class::method without trailing "()".
     */
    protected function requireExactUserArgCount(Frame $frame, string $function, int $expected): void
    {
        $given = $this->userArgCount($frame);
        if ($given !== $expected) {
            throw new \ArgumentCountError(self::exactUserArgCountMessage($function, $expected, $given));
        }
    }

    /**
     * Inclusive user-arity range → Zend ArgumentCountError (#30834).
     */
    protected function requireUserArgCountRange(Frame $frame, string $function, int $minimum, int $maximum): void
    {
        $given = $this->userArgCount($frame);
        if ($given < $minimum) {
            throw new \ArgumentCountError(self::atLeastUserArgCountMessage($function, $minimum, $given));
        }
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    public static function exactUserArgCountMessage(string $function, int $expected, int $given): string
    {
        return \sprintf(
            '%s() expects exactly %d argument%s, %d given',
            $function,
            $expected,
            1 === $expected ? '' : 's',
            $given
        );
    }

    public static function atMostUserArgCountMessage(string $function, int $maximum, int $given): string
    {
        return \sprintf(
            '%s() expects at most %d argument%s, %d given',
            $function,
            $maximum,
            1 === $maximum ? '' : 's',
            $given
        );
    }

    public static function atLeastUserArgCountMessage(string $function, int $minimum, int $given): string
    {
        return \sprintf(
            '%s() expects at least %d argument%s, %d given',
            $function,
            $minimum,
            1 === $minimum ? '' : 's',
            $given
        );
    }
}
