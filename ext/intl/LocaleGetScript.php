<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Locale::getScript() — OOP wrapper for {@see VmLocale::getScript()} (#6696). */
final class LocaleGetScript extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getScript');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::getScript() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        // Z_PARAM_STR $locale — Zend 8.4 deprecates null + coerces (#21368, locale.stub.php).
        $locale = VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'Locale::getScript',
            0,
            'locale'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLocale::getScript($locale));
        }
    }
}
