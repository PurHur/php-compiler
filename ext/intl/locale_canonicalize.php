<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_canonicalize() — php-src alias of Locale::canonicalize (#20738, AOT #20760). */
final class locale_canonicalize extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_canonicalize() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        // Z_PARAM_STR $locale — null TypeError on PROFILE=8.4 (#21078, locale.stub.php).
        $locale = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'locale_canonicalize',
            0,
            'locale'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::canonicalize($locale);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'locale_canonicalize() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitLocaleParser::canonicalize($context, $args[0], 'locale_canonicalize');
    }
}
