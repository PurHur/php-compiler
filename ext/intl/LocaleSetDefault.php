<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Locale::setDefault() — OOP wrapper for {@see VmLocale::setDefault()} (#9576). */
final class LocaleSetDefault extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setDefault');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::setDefault() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::setDefault',
            0,
            'locale'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::setDefault($locale));
        }
    }
}
