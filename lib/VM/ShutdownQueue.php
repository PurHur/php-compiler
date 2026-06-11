<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmClosureCall;

/**
 * FIFO shutdown callback queue (register_shutdown_function parity, issue #3120).
 */
final class ShutdownQueue
{
    /** @var list<array{kind: 'closure', closure: ClosureState, args: list<Variable>}|array{kind: 'callable', callable: Variable, args: list<Variable>}> */
    private static array $queue = [];

    private static bool $running = false;

    public static function reset(): void
    {
        self::$queue = [];
        self::$running = false;
    }

    public static function register(Variable $callable, Variable ...$args): void
    {
        self::$queue[] = ['kind' => 'callable', 'callable' => $callable, 'args' => $args];
    }

    public static function registerClosure(ClosureState $closure, Variable ...$args): void
    {
        self::$queue[] = ['kind' => 'closure', 'closure' => $closure, 'args' => $args];
    }

    public static function run(Context $context): void
    {
        if (self::$running || [] === self::$queue) {
            return;
        }
        self::$running = true;
        $pending = self::$queue;
        self::$queue = [];
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
        if (Variable::TYPE_STRING === $callable->type) {
            $name = strtolower($callable->toString());
            $fn = $context->functions[$name] ?? null;
            if ($fn instanceof Func\PHP) {
                $context->runtime->vm->invokePhpFunction($fn, ...$args);
            }

            return;
        }

        throw new \LogicException(
            'register_shutdown_function() callback must be a closure or function name string in this compiler build'
        );
    }
}
