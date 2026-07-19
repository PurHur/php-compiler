<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_parse() — php-src alias of Locale::parseLocale (#20738). */
final class locale_parse extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_parse() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'locale_parse',
            0,
            'locale'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::parseLocale($locale);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array(LocaleParseLocale::assocToHashTable($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_parse() JIT lowering not implemented; use VM');
    }
}
