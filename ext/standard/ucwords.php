<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ucwords() for strings (subset of PHP; ASCII letters).
 *
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 */
final class ucwords extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ucwords() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('ucwords() only supports strings in this compiler build');
        }
        $separators = VmString::TRIM_DEFAULT;
        if (2 === $argc) {
            $sepVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $sepVar->type) {
                throw new \LogicException('ucwords() separators must be a string in this compiler build');
            }
            $separators = $sepVar->toString();
        }
        $frame->returnVar->string(VmString::asciiUcwordsEx($v->toString(), $separators));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ucwords() requires one or two arguments');
        }
        $str = $this->jitString($context, $args[0], 'ucwords() argument #1');
        if (1 === $argc) {
            return $context->builder->call(
                $context->lookupFunction('__string__ucwords'),
                $str
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__ucwords_ex'),
            $str,
            $this->jitString($context, $args[1], 'ucwords() argument #2 ($separators)')
        );
    }
}
