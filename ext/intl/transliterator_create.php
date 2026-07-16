<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** transliterator_create() — php-src transliterator_methods.c (#6139). */
final class transliterator_create extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_create() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $id = VmTransliterator::coerceIdArg($frame->calledArgs[0], 'transliterator_create', 0);
        $dir = VmTransliterator::FORWARD;
        if ($argc >= 2) {
            $dir = VmTransliterator::coerceDirectionArg($frame->calledArgs[1], 'transliterator_create', 1);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::create($frame->vmContext, $id, $dir);
        if (null === $object) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('transliterator_create() is not implemented for JIT in this compiler build (issue #6139)');
    }
}
