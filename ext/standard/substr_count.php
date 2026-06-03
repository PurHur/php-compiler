<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Builtin\StringSubstrCount;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * substr_count() for two strings with optional offset and length (subset of PHP; LLVM JIT).
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
            $offVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offVar->type) {
                throw new \LogicException('substr_count() offset must be an integer in this compiler build');
            }
            $offset = $offVar->toInt();
        }
        $length = null;
        if (4 === $argc) {
            $lenVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL === $lenVar->type) {
                $length = null;
            } elseif (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('substr_count() length must be an integer in this compiler build');
            } else {
                $length = $lenVar->toInt();
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
        if ($argc >= 3 && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr_count() offset must be an integer in this compiler build');
        }
        if (4 === $argc
            && JITVariable::TYPE_NATIVE_LONG !== $args[3]->type
            && JITVariable::TYPE_VALUE !== $args[3]->type) {
            throw new \LogicException('substr_count() length must be an integer or null in this compiler build');
        }

        $hay = $this->jitString($context, $args[0], 'substr_count() argument #1');
        $needle = $this->jitString($context, $args[1], 'substr_count() argument #2');
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? $context->builder->truncOrBitCast($context->helper->loadValue($args[2]), $i64)
            : null;
        $length = 4 === $argc && JITVariable::TYPE_NATIVE_LONG === $args[3]->type
            ? $context->builder->truncOrBitCast($context->helper->loadValue($args[3]), $i64)
            : null;

        if (4 !== $argc || JITVariable::TYPE_NATIVE_LONG === $args[3]->type) {
            return JitSubstrCount::count($context, $hay, $needle, $offset, $length);
        }
        // Nullable $length would force a CFG split around JitSubstrCount::countInline (which builds its own CFG),
        // so lower via the runtime helper with an explicit "length is null" flag instead.
        StringSubstrCount::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);

        $hayLen = $context->builder->load($context->builder->structGep($hay, $map['length']));
        $needleLen = $context->builder->load($context->builder->structGep($needle, $map['length']));
        $hayPtr = $context->builder->structGep($hay, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);

        $offsetVal = null === $offset ? $zero : $offset;
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $args[3]);
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
        $context->builder->branch($done);

        $context->builder->positionAtEnd($lenBlock);
        $lenI64 = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $lenPhi = $context->builder->phi($i64);
        $lenPhi->addIncoming($zero, $nullBlock);
        $lenPhi->addIncoming($lenI64, $lenBlock);
        $isNullPhi = $context->builder->phi($i32);
        $isNullPhi->addIncoming($i32->constInt(1, false), $nullBlock);
        $isNullPhi->addIncoming($i32->constInt(0, false), $lenBlock);

        $fn = $context->lookupFunction('phpc_substr_count');

        return $context->builder->call(
            $fn,
            $hayPtr,
            $context->builder->truncOrBitCast($hayLen, $sizeT),
            $needlePtr,
            $context->builder->truncOrBitCast($needleLen, $sizeT),
            $offsetVal,
            $lenPhi,
            $isNullPhi
        );
    }
}
