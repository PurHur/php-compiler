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
            throw new \LogicException('call_user_func() requires at least one argument');
        }
        $ctx = VmReflection::requireContext($frame);
        $callback = $frame->calledArgs[0];
        $entries = self::collectForwardedArgEntries($frame);
        $result = [] === $entries
            ? VmCallable::invoke($ctx, $callback)
            : VmCallable::invokeWithArgEntries($ctx, $callback, $entries);
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
            if (Variable::TYPE_ARRAY === $sole->type) {
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
        if (\count($args) < 1) {
            throw new \LogicException('call_user_func() requires at least one argument');
        }

        return JitCallUserFunc::invoke($context, $args[0], \array_slice($args, 1));
    }
}
