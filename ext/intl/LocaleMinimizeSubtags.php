<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** Locale::minimizeSubtags() — php-src locale_minimize_subtags (#20927, GH-18344). */
final class LocaleMinimizeSubtags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('minimizeSubtags');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::minimizeSubtags() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::minimizeSubtags',
            0,
            'locale'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::minimizeSubtags($locale);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('Locale::minimizeSubtags() JIT lowering not implemented; use VM');
    }
}
