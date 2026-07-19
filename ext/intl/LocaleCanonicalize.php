<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Locale::canonicalize() — php-src locale_canonicalize (#20738). */
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
        $locale = VmString::coerceStringBuiltinArg(
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
}
