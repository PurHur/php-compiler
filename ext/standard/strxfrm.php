<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrxfrm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strxfrm() — locale-aware string transformation (#4376, #30420).
 *
 * JIT/AOT: {@see StringStrxfrm} → StrxfrmJitHelper; NestedJIT libc leaf.
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

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('strxfrm() requires exactly one argument');
        }

        if (null !== ($args[0]->compileTimeString ?? null)) {
            return $this->compileTimeString(
                $context,
                VmLocaleCollate::strxfrm($args[0]->compileTimeString)
            );
        }

        return StringStrxfrm::invoke($context, $args[0]);
    }

    private function compileTimeString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
