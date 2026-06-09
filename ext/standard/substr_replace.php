<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitPregSubject;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        if (null === $frame->returnVar) {
            return;
        }
        $stringVar = VmPreg::requireStringOrArraySubject(
            $frame->calledArgs[0],
            'substr_replace',
            0,
            'string'
        );
        if (Variable::TYPE_STRING !== $stringVar->type) {
            throw new \LogicException('substr_replace() array string operand is not supported in this compiler build');
        }
        $replace = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'substr_replace', 1, 'replace');
        $offsetInt = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'substr_replace', 3, 'offset');
        $length = null;
        if (4 === $argc) {
            $lengthArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthArg->type) {
                $length = VmMath::parseIntBuiltinArg($frame->calledArgs[3], 'substr_replace', 4, 'length');
            }
        }
        $frame->returnVar->string(VmString::substr_replace(
            $stringVar->toString(),
            $replace,
            $offsetInt,
            $length
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('substr_replace() requires three or four arguments in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr_replace() offset must be an integer in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $lengthVal = $i64->constInt(0, false);
        $hasLength = $i32->constInt(0, false);
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[3]->type) {
                $lengthVal = $this->jitLong($context, $args[3], 'substr_replace() length');
                $hasLength = $i32->constInt(1, false);
            } elseif (JITVariable::TYPE_VALUE === $args[3]->type) {
                if (!$args[3]->isNullConstant) {
                    throw new \LogicException('substr_replace() length must be an integer or literal null in this compiler build');
                }
                $lengthVal = $i64->constInt(0, false);
                $hasLength = $i32->constInt(0, false);
            } else {
                throw new \LogicException('substr_replace() length must be an integer or null in this compiler build');
            }
        }

        JitPregSubject::requireStringOrArray($context, $args[0], 'substr_replace', 0, 'string');
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('substr_replace() array string operand is not supported in this compiler build');
        }

        return JitSubstrReplace::replace(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'substr_replace', 0, 'string'),
            JitStringBuiltinArg::lower($context, $args[1], 'substr_replace', 1, 'replace'),
            $this->jitLong($context, $args[2], 'substr_replace() offset'),
            $lengthVal,
            $hasLength
        );
    }
}
