<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** Locale::getDisplayKeyword() — php-src locale_get_display_keyword (#20928). */
final class LocaleGetDisplayKeyword extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDisplayKeyword');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'Locale::getDisplayKeyword() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'Locale::getDisplayKeyword() expects at most 2 arguments, '.$argc.' given'
            );
        }
        $keyword = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::getDisplayKeyword',
            0,
            'keyword'
        );
        $displayLocale = null;
        if (isset($frame->calledArgs[1])) {
            $displayArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $displayArg->type) {
                $displayLocale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'Locale::getDisplayKeyword',
                    1,
                    'displayLocale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = VmLocale::getDisplayKeyword($keyword, $displayLocale);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }
}
