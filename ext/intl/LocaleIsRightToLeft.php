<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** Locale::isRightToLeft() — php-src locale_is_right_to_left (#20927, GH-18345). */
final class LocaleIsRightToLeft extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isRightToLeft');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::isRightToLeft() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::isRightToLeft',
            0,
            'locale'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::isRightToLeft($locale));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('Locale::isRightToLeft() JIT lowering not implemented; use VM');
    }
}
