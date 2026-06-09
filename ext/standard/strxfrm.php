<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strxfrm() — locale-aware string transformation (libc strxfrm; issue #4376).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strxfrm)
 */
final class strxfrm extends Internal
{
    public function __construct()
    {
        parent::__construct('strxfrm');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('strxfrm() requires exactly one argument');
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strxfrm', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmLocaleCollate::strxfrm($string));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('strxfrm() is VM-only in this compiler build (issue #4376)');
    }
}
