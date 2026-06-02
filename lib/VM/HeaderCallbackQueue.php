<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmClosureCall;

/**
 * FIFO header callback queue (header_register_callback parity, issue #3759).
 *
 * php-src: ext/standard/head.c — php_header_register_callback()
 */
final class HeaderCallbackQueue
{
    /** @var list<Variable> */
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
        self::$queue[] = $callable;

        return true;
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

    private static function invoke(Context $context, Variable $callable): void
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_NULL === $callable->type) {
            return;
        }
        if (VmClosureCall::isClosure($callable)) {
            VmClosureCall::invoke($context, VmClosureCall::resolve($callable));

            return;
        }
        if (Variable::TYPE_STRING === $callable->type) {
            $name = strtolower($callable->toString());
            $fn = $context->functions[$name] ?? null;
            if ($fn instanceof Func\PHP) {
                $context->runtime->vm->invokePhpFunction($fn);
            }

            return;
        }

        throw new \LogicException(
            'header_register_callback() callback must be a closure or function name string in this compiler build'
        );
    }
}
