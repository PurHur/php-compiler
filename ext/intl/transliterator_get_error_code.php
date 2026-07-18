<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** transliterator_get_error_code() — php-src transliterator_methods.c (#20719). */
final class transliterator_get_error_code extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_get_error_code');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_get_error_code() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $tr = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $tr->type
            || !VmTransliterator::isTransliteratorObject($tr->toObject())) {
            throw new \TypeError(\sprintf(
                'transliterator_get_error_code(): Argument #1 ($transliterator) must be of type Transliterator, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($tr)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmTransliterator::getErrorCode($tr->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('transliterator_get_error_code() is not implemented for JIT in this compiler build (issue #20719)');
    }
}
