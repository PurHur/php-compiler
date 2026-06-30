<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * substr_count() for two strings with optional offset and length (php-src ext/standard/string.c).
 */
final class substr_count extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('substr_count() requires two to four arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $haystack = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'substr_count', 0, 'haystack');
        $needle = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'substr_count', 1, 'needle');
        $offset = 0;
        if ($argc >= 3) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'substr_count', 3, 'offset');
        }
        $length = null;
        if (4 === $argc) {
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 3, 'substr_count', 4, 'length');
        }
        $frame->returnVar->int(
            VmString::substr_count($haystack, $needle, $offset, $length)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('substr_count() requires two to four arguments in this compiler build');
        }

        $hay = JitStringBuiltinArg::lower($context, $args[0], 'substr_count', 0, 'haystack');
        $needle = JitStringBuiltinArg::lower($context, $args[1], 'substr_count', 1, 'needle');
        $offset = $argc >= 3
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'substr_count', 3, 'offset')
            : null;

        if (4 !== $argc) {
            return JitSubstrCount::count($context, $hay, $needle, $offset, null);
        }

        if (JITVariable::TYPE_NATIVE_LONG === $args[3]->type
            || JITVariable::TYPE_STRING === $args[3]->type) {
            $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $args[3], 'substr_count', 4, 'length');

            return JitSubstrCount::count($context, $hay, $needle, $offset, $length);
        }

        if (JITVariable::TYPE_VALUE !== $args[3]->type) {
            throw new \LogicException('substr_count() length must be an integer or null in this compiler build');
        }

        return $this->jitSubstrCountNullableLength($context, $hay, $needle, $offset, $args[3]);
    }

    private function jitSubstrCountNullableLength(
        Context $context,
        Value $hay,
        Value $needle,
        ?Value $offset,
        JITVariable $lengthArg
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $lengthArg);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $valueMap['type']));
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );

        $nullBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'substr_count_len_null');
        $lenBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'substr_count_len_value');
        $done = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'substr_count_len_done');
        $context->builder->branchIf($isNull, $nullBlock, $lenBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = JitSubstrCount::count($context, $hay, $needle, $offset, null);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($lenBlock);
        $lenResult = JitSubstrCount::count(
            $context,
            $hay,
            $needle,
            $offset,
            JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $lengthArg, 'substr_count', 4, 'length')
        );
        $lenEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($nullResult, $nullEnd);
        $phi->addIncoming($lenResult, $lenEnd);

        return $phi;
    }
}
