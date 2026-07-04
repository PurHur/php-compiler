<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Locale::getDefault() — OOP wrapper for {@see VmLocale::getDefault()} (#9576). */
final class LocaleGetDefault extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDefault');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::getDefault() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLocale::getDefault());
        }
    }
}
