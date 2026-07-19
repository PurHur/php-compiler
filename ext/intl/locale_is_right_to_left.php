<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_is_right_to_left() — php-src alias of Locale::isRightToLeft (#20927). */
final class locale_is_right_to_left extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_is_right_to_left() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'locale_is_right_to_left',
            0,
            'locale'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::isRightToLeft($locale));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_is_right_to_left() JIT lowering not implemented; use VM');
    }
}
