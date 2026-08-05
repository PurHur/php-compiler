<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_get_default() — default BCP-47 locale id (php-src ext/intl/php_intl.c; #9576). */
final class locale_get_default extends Internal
{
    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('locale_get_default() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLocale::getDefault());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \ArgumentCountError(
                'locale_get_default() expects exactly 0 arguments, '.\count($args).' given'
            );
        }

        return JitLocaleParser::getDefault($context);
    }
}
