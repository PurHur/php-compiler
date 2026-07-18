<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** transliterator_create_inverse() — php-src transliterator_methods.c (#20719). */
final class transliterator_create_inverse extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_create_inverse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_create_inverse() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $tr = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $tr->type
            || !VmTransliterator::isTransliteratorObject($tr->toObject())) {
            throw new \TypeError(\sprintf(
                'transliterator_create_inverse(): Argument #1 ($transliterator) must be of type Transliterator, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($tr)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::createInverse($frame->vmContext, $tr->toObject());
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('transliterator_create_inverse() is not implemented for JIT in this compiler build (issue #20719)');
    }
}
