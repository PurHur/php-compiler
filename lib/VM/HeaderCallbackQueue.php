<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmClosureCall;

/**
 * FIFO header callback queue (header_register_callback parity, issue #3759 / #3492).
 *
 * php-src: ext/standard/head.c — php_header_register_callback()
 *
 * Closures are retained as {@see ClosureState} (not live {@see Variable} slots):
 * outgoing call-arg temp release can drop ObjectEntry refs on the original arg
 * while a copied Variable still points at the destroyed entry.
 */
final class HeaderCallbackQueue
{
    /** @var list<ClosureState|string> */
    private static array $queue = [];

    private static bool $running = false;

    private static bool $flushed = false;

    public static function reset(): void
    {
        self::$queue = [];
        self::$running = false;
        self::$flushed = false;
    }

    public static function register(Variable $callable): bool
    {
        $callable = $callable->resolveIndirect();
        if (VmClosureCall::isClosure($callable)) {
            self::$queue[] = VmClosureCall::resolve($callable);

            return true;
        }
        if (Variable::TYPE_STRING === $callable->type) {
            self::$queue[] = strtolower($callable->toString());

            return true;
        }

        throw new \TypeError(
            'header_register_callback(): Argument #1 ($callback) must be a valid callback, no array or string given'
        );
    }

    /**
     * Invoke registered callbacks once before the first body byte reaches SAPI.
     */
    public static function runBeforeOutput(Context $context): void
    {
        if (self::$flushed || self::$running || [] === self::$queue) {
            return;
        }
        self::$running = true;
        $pending = self::$queue;
        self::$queue = [];
        foreach ($pending as $callable) {
            self::invoke($context, $callable);
        }
        self::$running = false;
        self::$flushed = true;
    }

    private static function invoke(Context $context, ClosureState|string $callable): void
    {
        if ($callable instanceof ClosureState) {
            VmClosureCall::invoke($context, $callable);

            return;
        }
        $fn = $context->functions[$callable] ?? null;
        if ($fn instanceof Func\PHP) {
            $context->runtime->vm->invokePhpFunction($fn);
        }
    }
}
