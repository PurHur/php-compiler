<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_filter_matches() — language tag prefix filter (#20036). */
final class locale_filter_matches extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'locale_filter_matches() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'locale_filter_matches() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $langtag = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'locale_filter_matches', 0, 'languageTag');
        $locale = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'locale_filter_matches', 1, 'locale');
        $canonicalize = false;
        if ($argc >= 3) {
            $canonicalize = LocaleLookup::coerceBool(
                $frame->calledArgs[2],
                'locale_filter_matches',
                2,
                'canonicalize'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::filterMatches($langtag, $locale, $canonicalize));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_filter_matches() JIT lowering not implemented; use VM');
    }
}
