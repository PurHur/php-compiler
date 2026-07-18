<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** transliterator_create_from_rules() — php-src transliterator_methods.c (#20719). */
final class transliterator_create_from_rules extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_create_from_rules');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_create_from_rules() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $rules = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'transliterator_create_from_rules',
            0,
            'rules'
        );
        $dir = VmTransliterator::FORWARD;
        if ($argc >= 2) {
            $dir = VmTransliterator::coerceDirectionArg(
                $frame->calledArgs[1],
                'transliterator_create_from_rules',
                1
            );
        }
        VmTransliterator::assertDirection($dir, 'transliterator_create_from_rules');
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::createFromRules($frame->vmContext, $rules, $dir);
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('transliterator_create_from_rules() is not implemented for JIT in this compiler build (issue #20719)');
    }
}
