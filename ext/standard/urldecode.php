<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** urldecode() for strings (subset of PHP; JIT/AOT via __string__urldecode). */
final class urldecode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('urldecode() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('urldecode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::urldecode($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('urldecode() requires exactly one argument');
        }

        return JitUrlencode::urldecode($context, self::jitStringArg($context, $args[0]));
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('urldecode() only supports strings in this compiler build');
    }
}
