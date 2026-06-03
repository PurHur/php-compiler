<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\Variable;
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
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $needle->type) {
            throw new \LogicException('substr_count() only supports strings in this compiler build');
        }
        $offset = 0;
        if ($argc >= 3) {
            $offset = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'substr_count',
                3,
                'offset'
            );
        }
        $length = null;
        if (4 === $argc) {
            $lenVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lenVar->type) {
                $length = VmMath::parseIntBuiltinArg(
                    $lenVar,
                    'substr_count',
                    4,
                    'length'
                );
            }
        }
        $frame->returnVar->int(
            VmString::substr_count($haystack->toString(), $needle->toString(), $offset, $length)
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

        $hay = $this->jitString($context, $args[0], 'substr_count() argument #1');
        $needle = $this->jitString($context, $args[1], 'substr_count() argument #2');
        $offset = $argc >= 3
            ? $this->jitLong($context, $args[2], 'substr_count() argument #3 ($offset)')
            : null;

        if (4 !== $argc) {
            return JitSubstrCount::count($context, $hay, $needle, $offset, null);
        }

        if (JITVariable::TYPE_NATIVE_LONG === $args[3]->type
            || JITVariable::TYPE_STRING === $args[3]->type) {
            $length = $this->jitLong($context, $args[3], 'substr_count() argument #4 ($length)');

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
            $this->jitLong($context, $lengthArg, 'substr_count() argument #4 ($length)')
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
