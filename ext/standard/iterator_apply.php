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
 * Excess/missing argc → Zend ArgumentCountError (#30603).
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
        // php-src ext/spl/php_spl.c — ArgumentCountError arity 2..3 (#30603).
        $this->requireArgCountRange($frame, 'iterator_apply', 2, 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $vm = $frame->vmContext->runtime->vm;
        $iterable = VmIteratorWalk::assertTraversableOnly($frame->calledArgs[0], $ctx, 'iterator_apply');
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
        // Catchable ArgumentCountError under AOT try/catch (#30603).
        if (!$this->requireArgCountRangeJit($context, $args, 'iterator_apply', 2, 3)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
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
