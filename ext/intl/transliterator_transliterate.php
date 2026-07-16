<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** transliterator_transliterate() — php-src transliterator_methods.c (#6139). */
final class transliterator_transliterate extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_transliterate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_transliterate() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        $tr = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $tr->type
            || !VmTransliterator::isTransliteratorObject($tr->toObject())) {
            throw new \TypeError(\sprintf(
                'transliterator_transliterate(): Argument #1 ($transliterator) must be of type Transliterator, %s given',
                ReflectionSupport::valueTypeLabelPublic($tr)
            ));
        }
        $subject = VmTransliterator::coerceSubjectArg($frame->calledArgs[1], 'transliterator_transliterate', 1);
        $start = 0;
        $end = -1;
        if ($argc >= 3) {
            $start = (int) $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        if ($argc >= 4) {
            $end = (int) $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $result = VmTransliterator::transliterate($tr->toObject(), $subject, $start, $end);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('transliterator_transliterate() is not implemented for JIT in this compiler build (issue #6139)');
    }
}
