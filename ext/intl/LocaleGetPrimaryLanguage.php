<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Locale::getPrimaryLanguage() — OOP wrapper for {@see VmLocale::getPrimaryLanguage()} (#6696). */
final class LocaleGetPrimaryLanguage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPrimaryLanguage');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::getPrimaryLanguage() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::getPrimaryLanguage',
            0,
            'locale'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLocale::getPrimaryLanguage($locale));
        }
    }
}
