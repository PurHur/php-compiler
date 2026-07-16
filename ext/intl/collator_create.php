<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * collator_create() — procedural alias of Collator::create (php-src collator_create.c; #5747).
 */
final class collator_create extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_create() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $locale = VmCollator::coerceLocaleArg($frame->calledArgs[0], 'collator_create', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmCollator::create($frame->vmContext, $locale));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('collator_create() is not implemented for JIT in this compiler build (issue #5747)');
    }
}
