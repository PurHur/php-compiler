<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** urlencode() for strings (subset of PHP; JIT/AOT via __string__urlencode). */
final class urlencode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('urlencode() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('urlencode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::urlencode($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('urlencode() requires exactly one argument');
        }

        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::urlencode($literal))
            );
        }

        return JitUrlencode::urlencode($context, $this->jitString($context, $args[0], 'urlencode() argument #1'));
    }

}
