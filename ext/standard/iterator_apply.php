<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * iterator_apply() — invoke a callback over a traversable (ext/spl/iterator.c, #3313).
 *
 * @see https://github.com/php/php-src/blob/master/ext/spl/php_spl.c PHP_FUNCTION(iterator_apply)
 */
final class iterator_apply extends Internal
{
    public function __construct()
    {
        parent::__construct('iterator_apply');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('iterator_apply() requires two or three arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $vm = $frame->vmContext->runtime->vm;
        $iterable = VmIteratorWalk::assertTraversable($frame->calledArgs[0], $ctx, 'iterator_apply');
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $params = 3 === $argc
            ? $frame->calledArgs[2]
            : (function () {
                $empty = new \PHPCompiler\VM\Variable();
                $empty->array(new \PHPCompiler\VM\HashTable());

                return $empty;
            })();
        $count = VmIteratorWalk::apply($vm, $frame, $iterable, $callback, $params);
        $frame->returnVar->int($count);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('iterator_apply() requires two or three arguments');
        }
        if (!ArrayMapCallbackPolicy::isClosureJitLowerable($args[1])) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        $params = $args[2] ?? self::emptyArrayArg($context);

        return JitIteratorWalk::apply($context, $args[0], $args[1], $params);
    }

    private static function emptyArrayArg(Context $context): JITVariable
    {
        $ht = \PHPCompiler\JIT\HashTableHelper::alloc($context);

        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $ht
        );
    }
}
