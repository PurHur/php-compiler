<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** Locale::getDisplayName() — OOP wrapper for {@see VmLocale::getDisplayName()} (#6696). */
final class LocaleGetDisplayName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDisplayName');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'Locale::getDisplayName() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'Locale::getDisplayName() expects at most 2 arguments, '.$argc.' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::getDisplayName',
            0,
            'locale'
        );
        $displayLocale = null;
        if (isset($frame->calledArgs[1])) {
            $displayArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $displayArg->type) {
                $displayLocale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'Locale::getDisplayName',
                    1,
                    'displayLocale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = VmLocale::getDisplayName($locale, $displayLocale);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }
}
