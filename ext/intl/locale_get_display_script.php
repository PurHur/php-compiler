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

/** locale_get_display_script() — php-src alias of Locale::getDisplayScript (#20755). */
final class locale_get_display_script extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'locale_get_display_script() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'locale_get_display_script() expects at most 2 arguments, '.$argc.' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'locale_get_display_script',
            0,
            'locale'
        );
        $displayLocale = null;
        if (isset($frame->calledArgs[1])) {
            $displayArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $displayArg->type) {
                $displayLocale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'locale_get_display_script',
                    1,
                    'displayLocale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = VmLocale::getDisplayScript($locale, $displayLocale);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_get_display_script() JIT lowering not implemented; use VM');
    }
}
