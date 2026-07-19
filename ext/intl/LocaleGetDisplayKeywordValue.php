<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** Locale::getDisplayKeywordValue() — php-src locale_get_display_keyword_value (#20928). */
final class LocaleGetDisplayKeywordValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDisplayKeywordValue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'Locale::getDisplayKeywordValue() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'Locale::getDisplayKeywordValue() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::getDisplayKeywordValue',
            0,
            'locale'
        );
        $keyword = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'Locale::getDisplayKeywordValue',
            1,
            'keyword'
        );
        $displayLocale = null;
        if (isset($frame->calledArgs[2])) {
            $displayArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $displayArg->type) {
                $displayLocale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[2],
                    'Locale::getDisplayKeywordValue',
                    2,
                    'displayLocale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = VmLocale::getDisplayKeywordValue($locale, $keyword, $displayLocale);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }
}
