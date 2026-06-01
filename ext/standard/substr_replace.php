<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** substr_replace() — replace substring slice (php-src ext/standard/string.c; issue #3356). */
final class substr_replace extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('substr_replace() requires three or four arguments in this compiler build');
        }
        $string = $frame->calledArgs[0]->resolveIndirect();
        $replace = $frame->calledArgs[1]->resolveIndirect();
        $offset = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $string->type
            || Variable::TYPE_STRING !== $replace->type
            || Variable::TYPE_INTEGER !== $offset->type) {
            throw new \LogicException('substr_replace() requires two strings and an integer offset in this compiler build');
        }
        $length = null;
        if (4 === $argc) {
            $lengthArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lengthArg->type) {
                throw new \LogicException('substr_replace() length must be an integer in this compiler build');
            }
            $length = $lengthArg->toInt();
        }
        $frame->returnVar->string(VmString::substr_replace(
            $string->toString(),
            $replace->toString(),
            $offset->toInt(),
            $length
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('substr_replace() requires three or four arguments in this compiler build');
        }
        StringCslashes::ensureLinked($context);
        if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr_replace() offset must be an integer in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $lengthVal = $i64->constInt(0, false);
        $hasLength = $i32->constInt(0, false);
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
                throw new \LogicException('substr_replace() length must be an integer in this compiler build');
            }
            $lengthVal = $this->jitLong($context, $args[3], 'substr_replace() length');
            $hasLength = $i32->constInt(1, false);
        }

        return JitSubstrReplace::replace(
            $context,
            $this->jitString($context, $args[0], 'substr_replace() argument #1'),
            $this->jitString($context, $args[1], 'substr_replace() argument #2'),
            $this->jitLong($context, $args[2], 'substr_replace() offset'),
            $lengthVal,
            $hasLength
        );
    }
}
