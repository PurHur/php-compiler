<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;

/**
 * Registered tick callbacks (register_tick_function parity, issue #3343).
 */
final class TickQueue
{
    /** @var list<array{kind: 'closure', closure: ClosureState, args: list<Variable>}|array{kind: 'callable', callable: Variable, args: list<Variable>}> */
    private static array $queue = [];

    private static bool $running = false;

    public static function isRunning(): bool
    {
        return self::$running;
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$running = false;
    }

    public static function register(Variable $callable, Variable ...$args): void
    {
        $callableCopy = new Variable();
        $callableCopy->copyFrom($callable->resolveIndirect());
        self::$queue[] = ['kind' => 'callable', 'callable' => $callableCopy, 'args' => $args];
    }

    public static function registerClosure(ClosureState $closure, Variable ...$args): void
    {
        self::$queue[] = ['kind' => 'closure', 'closure' => $closure, 'args' => $args];
    }

    public static function unregister(Variable $callable): void
    {
        $callable = $callable->resolveIndirect();
        if (VmClosureCall::isClosure($callable)) {
            $target = VmClosureCall::resolve($callable);
            foreach (self::$queue as $i => $entry) {
                if ('closure' === $entry['kind'] && $entry['closure'] === $target) {
                    array_splice(self::$queue, $i, 1);

                    return;
                }
            }

            return;
        }
        foreach (self::$queue as $i => $entry) {
            if ('callable' !== $entry['kind']) {
                continue;
            }
            if (self::callablesMatch($entry['callable'], $callable)) {
                array_splice(self::$queue, $i, 1);

                return;
            }
        }
    }

    public static function run(Context $context): void
    {
        if (self::$running || [] === self::$queue) {
            return;
        }
        self::$running = true;
        $pending = self::$queue;
        foreach ($pending as $entry) {
            if ('closure' === $entry['kind']) {
                VmClosureCall::invoke($context, $entry['closure'], ...$entry['args']);
                continue;
            }
            self::invoke($context, $entry['callable'], ...$entry['args']);
        }
        self::$running = false;
    }

    private static function invoke(Context $context, Variable $callable, Variable ...$args): void
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_NULL === $callable->type) {
            return;
        }
        if (VmClosureCall::isClosure($callable)) {
            VmClosureCall::invoke($context, VmClosureCall::resolve($callable), ...$args);

            return;
        }
        VmCallable::invoke($context, $callable, ...$args);
    }

    private static function callablesMatch(Variable $registered, Variable $needle): bool
    {
        $registered = $registered->resolveIndirect();
        $needle = $needle->resolveIndirect();
        if ($registered->type !== $needle->type) {
            return false;
        }
        if (Variable::TYPE_STRING === $registered->type) {
            return $registered->toString() === $needle->toString();
        }
        if (Variable::TYPE_ARRAY === $registered->type) {
            return $registered->toArray() === $needle->toArray();
        }

        return $registered === $needle;
    }
}
