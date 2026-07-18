<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** transliterator_list_ids() — php-src transliterator_methods.c (#20719). */
final class transliterator_list_ids extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_list_ids');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_list_ids() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ids = VmTransliterator::listIDs();
        if (false === $ids) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($ids);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('transliterator_list_ids() is not implemented for JIT in this compiler build (issue #20719)');
    }
}
