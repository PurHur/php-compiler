<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringNlLanginfo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * nl_langinfo() — locale item lookup (issue #3382, #30404).
 *
 * JIT/AOT: {@see StringNlLanginfo} → NlLanginfoJitHelper; NestedJIT libc leaf.
 * php-src: ext/standard/nl_langinfo.c — PHP_FUNCTION(nl_langinfo)
 */
final class nl_langinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('nl_langinfo');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf('nl_langinfo() expects exactly 1 argument, %d given', \count($frame->calledArgs))
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $item = VmMath::parseZParamLongBuiltinArgForFrame($frame, 0, 'nl_langinfo', 1, 'item');
        $result = VmLocale::nlLanginfo($item, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('nl_langinfo() expects exactly one argument in this compiler build');
        }

        return StringNlLanginfo::invoke($context, $args[0]);
    }

}
