<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** locale_lookup() — RFC 4647 locale lookup (php-src locale_methods.c; #20036). */
final class locale_lookup extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'locale_lookup() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'locale_lookup() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $tags = LocaleLookup::coerceStringList($frame->calledArgs[0], 'locale_lookup', 0, 'languageTag');
        $locale = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'locale_lookup', 1, 'locale');
        $canonicalize = false;
        if ($argc >= 3) {
            $canonicalize = LocaleLookup::coerceBool($frame->calledArgs[2], 'locale_lookup', 2, 'canonicalize');
        }
        $default = null;
        if ($argc >= 4) {
            $defaultVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $defaultVar->type) {
                $default = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[3],
                    'locale_lookup',
                    3,
                    'defaultLocale'
                );
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLocale::lookup($tags, $locale, $canonicalize, $default));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_lookup() JIT lowering not implemented; use VM');
    }
}
