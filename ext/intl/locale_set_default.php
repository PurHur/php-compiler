<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_set_default() — set default BCP-47 locale id (php-src ext/intl/php_intl.c; #9576). */
final class locale_set_default extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_set_default() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'locale_set_default',
            0,
            'locale'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::setDefault($locale));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'locale_set_default() JIT runtime lowering is deferred; use VM (#9576)'
        );
    }
}
