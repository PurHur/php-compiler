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
        // php-src ext/spl/php_spl.c — ArgumentCountError (#30575).
        $this->requireExactArgCount($frame, 'iterator_count', 1);
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
        // Catchable ArgumentCountError (AOT/JIT) — #30575.
        if (!$this->requireExactJitArgCount($context, $args, 'iterator_count', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, true);
        }

        return JitIteratorWalk::count($context, $args[0]);
    }
}
