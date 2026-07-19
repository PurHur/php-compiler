<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** locale_get_display_keyword_value() — php-src alias of Locale::getDisplayKeywordValue (#20928). */
final class locale_get_display_keyword_value extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'locale_get_display_keyword_value() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'locale_get_display_keyword_value() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'locale_get_display_keyword_value',
            0,
            'locale'
        );
        $keyword = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'locale_get_display_keyword_value',
            1,
            'keyword'
        );
        $displayLocale = null;
        if (isset($frame->calledArgs[2])) {
            $displayArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $displayArg->type) {
                $displayLocale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[2],
                    'locale_get_display_keyword_value',
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

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_get_display_keyword_value() JIT lowering not implemented; use VM');
    }
}
