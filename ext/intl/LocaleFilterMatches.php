<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** Locale::filterMatches() — php-src locale_methods.c (#20036). */
final class LocaleFilterMatches extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('filterMatches');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'Locale::filterMatches() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'Locale::filterMatches() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $langtag = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'Locale::filterMatches', 0, 'languageTag');
        $locale = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'Locale::filterMatches', 1, 'locale');
        $canonicalize = false;
        if ($argc >= 3) {
            $canonicalize = LocaleLookup::coerceBool($frame->calledArgs[2], 'Locale::filterMatches', 2, 'canonicalize');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::filterMatches($langtag, $locale, $canonicalize));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLocaleFilterMatches::filterMatches($context, ...$args);
    }
}
