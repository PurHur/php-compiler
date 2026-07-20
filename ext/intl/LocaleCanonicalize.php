<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** Locale::canonicalize() — php-src locale_canonicalize (#20738, AOT #20760). */
final class LocaleCanonicalize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('canonicalize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::canonicalize() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        // Z_PARAM_STR $locale — null TypeError on PROFILE=8.4 (#21078, locale.stub.php).
        $locale = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'Locale::canonicalize',
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
                'Locale::canonicalize() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitLocaleParser::canonicalize($context, $args[0], 'Locale::canonicalize');
    }
}
