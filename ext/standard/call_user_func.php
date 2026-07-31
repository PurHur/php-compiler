<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * call_user_func() — invoke a callable with arguments (issue #3132).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(call_user_func)
 */
final class call_user_func extends Internal
{
    public function __construct()
    {
        parent::__construct('call_user_func');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'call_user_func() expects at least 1 argument, %d given',
                $argc
            ));
        }
        $ctx = VmReflection::requireContext($frame);
        $callback = $frame->calledArgs[0];
        $entries = self::collectForwardedArgEntries($frame);
        $result = [] === $entries
            ? VmCallable::invokeAsWithScope('call_user_func', $ctx, $frame, $callback)
            : VmCallable::invokeWithArgEntries($ctx, $callback, $entries, 'call_user_func', $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    /**
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function collectForwardedArgEntries(Frame $frame): array
    {
        $argc = \count($frame->calledArgs);
        if ($argc <= 1) {
            return [];
        }
        if (2 === $argc) {
            $sole = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $sole->type && self::arrayArgShouldUnpack($sole)) {
                return VmCallable::arrayVariableToArgEntries($sole);
            }
        }
        $entries = [];
        for ($i = 1; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $entries[] = ['p', $copy];
        }

        return $entries;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'call_user_func() expects at least 1 argument, %d given',
                $argc
            ));
        }

        return JitCallUserFunc::invoke($context, $args[0], \array_slice($args, 1));
    }

    /** Named-arg lowering packs string keys; list arrays are single value args (#14829). */
    private static function arrayArgShouldUnpack(Variable $arrayVar): bool
    {
        foreach ($arrayVar->toArray()->iterateKeyed(false) as $pair) {
            [$keyVar, ] = $pair;
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $keyStr = $key->toString();
            if ('' !== $keyStr && !ctype_digit($keyStr)) {
                return true;
            }
        }

        return false;
    }
}
