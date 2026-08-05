<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM probe for VM stream-context hashtable handles (#6367, #8743). */
final class JitStreamContextRepresentation
{
    public static function markerKeyLiteral(Context $context): Value
    {
        return $context->builder->load(
            $context->constantStringFromString(VmStreamContext::MARKER_KEY)
        );
    }

    public static function isRepresentation(Context $context, Value $htPtr): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $htPtr,
            self::markerKeyLiteral($context)
        );
    }

    public static function isRepresentationArg(Context $context, JITVariable $arg): Value
    {
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return $context->constantFromBool(false);
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return self::isRepresentation($context, $context->helper->loadValue($arg));
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::isRepresentationValueBox($context, $arg);
        }

        return $context->constantFromBool(false);
    }

    private static function isRepresentationValueBox(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $htTy = $i8->constInt(JITVariable::TYPE_HASHTABLE, false);
        $isHt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $htTy);

        $falseBlock = BasicBlockHelper::append($context, 'stream_ctx_repr_false');
        $probeBlock = BasicBlockHelper::append($context, 'stream_ctx_repr_probe');
        $doneBlock = BasicBlockHelper::append($context, 'stream_ctx_repr_done');
        $context->builder->branchIf($isHt, $probeBlock, $falseBlock);

        $context->builder->positionAtEnd($falseBlock);
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($probeBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $isCtx = self::isRepresentation($context, $ht);
        $probeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1, 'stream_ctx_repr_phi');
        $phi->addIncoming($context->constantFromBool(false), $falseEnd);
        $phi->addIncoming($isCtx, $probeEnd);

        return $phi;
    }

    public static function readMarkerId(Context $context, Value $htPtr): Value
    {
        $valuePtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $htPtr,
            self::markerKeyLiteral($context)
        );

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    public static function hashtableFromArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('stream context operand must be a hashtable in this compiler build');
    }
}
