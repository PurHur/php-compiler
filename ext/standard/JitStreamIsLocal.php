<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringDir;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_is_local() via __compiler_stream_is_local(_uri) (#6173, #11358). */
final class JitStreamIsLocal
{
    /** @return Value i1 */
    public static function invokeArg(Context $context, JITVariable $arg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $arg, 'stream_is_local', 0, 'stream');
        if (null !== $arg->compileTimeString) {
            return $context->constantFromBool(VmStreamMeta::isLocalUri($arg->compileTimeString));
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::invokeUriString($context, $context->helper->loadValue($arg));
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::invokeValueBox($context, $arg);
        }

        return self::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $arg, 'stream_is_local() Argument #1 ($stream)'),
                $context->getTypeFromString('int64')
            )
        );
    }

    /** @return Value i1 */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_is_local'),
            $handleLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }

    /** @return Value i1 */
    private static function invokeUriString(Context $context, Value $strPtr): Value
    {
        StringDir::ensureLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->call($context->lookupFunction('__string__cstr'), $strPtr);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_is_local_uri'),
            $context->builder->pointerCast($cstr, $i8p)
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }

    /** @return Value i1 */
    private static function invokeValueBox(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $stringBb = BasicBlockHelper::append($context, 'stream_is_local_vbox_string');
        $handleBb = BasicBlockHelper::append($context, 'stream_is_local_vbox_handle');
        $doneBb = BasicBlockHelper::append($context, 'stream_is_local_vbox_done');
        $context->builder->branchIf($isString, $stringBb, $handleBb);

        $context->builder->positionAtEnd($stringBb);
        $strObj = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringResult = self::invokeUriString($context, $strObj);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($handleBb);
        $handleLong = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $handleResult = self::invoke($context, $handleLong);
        $handleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1, 'stream_is_local_vbox_phi');
        $phi->addIncoming($stringResult, $stringEnd);
        $phi->addIncoming($handleResult, $handleEnd);

        return $phi;
    }
}
