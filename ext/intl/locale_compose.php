<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_compose() — php-src alias of Locale::composeLocale (#20738). */
final class locale_compose extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_compose() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $subtags = LocaleComposeLocale::coerceSubtags($frame->calledArgs[0], 'locale_compose');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::composeLocale($subtags, 'locale_compose');
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_compose() JIT lowering not implemented; use VM');
    }
}
