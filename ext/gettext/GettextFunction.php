<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for gettext builtins; JIT via {@see JitGettext} (#3449, #8625).
 */
abstract class GettextFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        return match ($this->getName()) {
            'gettext' => JitGettext::gettext($context, ...$args),
            '_' => JitGettext::underscore($context, ...$args),
            'dgettext' => JitGettext::dgettext($context, ...$args),
            'dcgettext' => JitGettext::dcgettext($context, ...$args),
            'dngettext' => JitGettext::dngettext($context, ...$args),
            'ngettext' => JitGettext::ngettext($context, ...$args),
            'dcngettext' => JitGettext::dcngettext($context, ...$args),
            'bindtextdomain' => JitGettext::bindtextdomain($context, ...$args),
            'textdomain' => JitGettext::textdomain($context, ...$args),
            'bind_textdomain_codeset' => JitGettext::bindTextdomainCodeset($context, ...$args),
            default => throw new \LogicException($this->getName().'() JIT dispatch missing'),
        };
    }

    protected function requireArgCount(Frame $frame, int $expected, ?int $max = null): void
    {
        $argc = \count($frame->calledArgs);
        $max ??= $expected;
        if ($argc < $expected || $argc > $max) {
            throw new \ArgumentCountError(sprintf(
                '%s() expects %s %d argument%s, %d given',
                $this->getName(),
                $expected === $max ? 'exactly' : 'at most',
                $expected === $max ? $expected : $max,
                1 === ($expected === $max ? $expected : $max) ? '' : 's',
                $argc
            ));
        }
    }
}
