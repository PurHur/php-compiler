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
 * iterator_count() — count elements of a traversable (ext/spl/iterator.c, #3313).
 *
 * @see https://github.com/php/php-src/blob/master/ext/spl/php_spl.c PHP_FUNCTION(iterator_count)
 */
final class iterator_count extends Internal
{
    public function __construct()
    {
        parent::__construct('iterator_count');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('iterator_count() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $vm = $frame->vmContext->runtime->vm;
        $iterable = VmIteratorWalk::assertTraversable($frame->calledArgs[0], $ctx, 'iterator_count');
        $frame->returnVar->int(VmIteratorWalk::count($vm, $frame, $iterable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('iterator_count() requires exactly one argument');
        }

        return JitIteratorWalk::count($context, $args[0]);
    }
}
