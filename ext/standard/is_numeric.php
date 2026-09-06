<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamLifecycleJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * is_numeric() — Zend ext/standard/basic_functions.c parity (#5244).
 *
 * Returns false for arrays, objects, and resources without throwing.
 *
 * Excess argc → Zend ArgumentCountError (#30687; php-src Zend/zend_builtin_functions.c).
 */
final class is_numeric extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30687; Zend/zend_builtin_functions.c).
        $this->requireExactArgCount($frame, 'is_numeric', 1);
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isNumeric($v));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError under AOT try/catch (#30687 / peer #30653).
        if (!$this->requireExactJitArgCount($context, $args, 'is_numeric', 1)) {
            return $context->constantFromBool(false);
        }
        $arg = $args[0];
        // Lazy ±/×/`/` overflow (#37051): either the i64 ok arm or the f64 promote arm is
        // numeric (Zend IS_LONG / IS_DOUBLE). Short-circuit before JitIsResource on the
        // overflow-arm dummy i64 0 (#36386 leftover of #37051).
        if (
            null !== $arg->longArithOverflowFlag
            && (null !== $arg->longArithOverflowDoubleSlot || null !== $arg->longArithOverflowPromoted)
            && JITVariable::TYPE_NATIVE_LONG === $arg->type
        ) {
            return $context->constantFromBool(true);
        }
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                StreamLifecycleJit::implement($context);

                return $this->longIsNumeric(
                    $context,
                    $context->builder->truncOrBitCast(
                        JitLongArg::lower($context, $arg, 'is_numeric() argument #1'),
                        $context->getTypeFromString('int64')
                    )
                );
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->constantFromBool(true);
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            case JITVariable::TYPE_STRING:
                return $this->stringIsNumeric($context, $this->jitString($context, $arg, 'is_numeric() argument #1'));
            case JITVariable::TYPE_OBJECT:
            case JITVariable::TYPE_HASHTABLE:
                return $context->constantFromBool(false);
            case JITVariable::TYPE_VALUE:
                return $this->valueIsNumeric($context, $arg);
            default:
                return $context->constantFromBool(false);
        }
    }

    public static function isNumeric(Variable $v): bool
    {
        $v = $v->resolveIndirect();
        switch ($v->type) {
            case Variable::TYPE_INTEGER:
                if (ResourceSupport::isVmResource($v)) {
                    return false;
                }

                return true;
            case Variable::TYPE_FLOAT:
                return true;
            case Variable::TYPE_STRING:
                $s = $v->toString();

                return '' !== $s && \is_numeric($s);
            case Variable::TYPE_BOOLEAN:
            case Variable::TYPE_NULL:
            case Variable::TYPE_ENUM_CASE:
            case Variable::TYPE_OBJECT:
            case Variable::TYPE_ARRAY:
                return false;
            default:
                return false;
        }
    }

    private function stringIsNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldsFor($strPtr);
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'is_numeric_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
        LibcExtern::ensureStrtodDecl($context);
        $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
        $endPtr = $context->builder->load($endPtrSlot);
        $notConsumed = $context->builder->icmp(Builder::INT_EQ, $endPtr, $charPtr);
        $i64 = $context->getTypeFromString('int64');
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($notConsumed),
            $consumedAll
        );

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $numeric);
    }

    private function longIsNumeric(Context $context, Value $handleLong): Value
    {
        $falseVal = $context->constantFromBool(false);
        $trueVal = $context->constantFromBool(true);
        $isRes = JitIsResource::invoke($context, $handleLong);

        return $context->builder->select($isRes, $falseVal, $trueVal);
    }

    private function valueIsNumeric(Context $context, JITVariable $arg): Value
    {
        StreamLifecycleJit::implement($context);
        // Named locals from overflow ±/× are TYPE_VALUE boxes (AssignOperand materialize).
        // Eager __value__readString / readLong before type select SIGSEGVs on double
        // payloads (strtod on garbage) — branch per tag like floatval (#36386 leftover of #37051).
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->constantFromBool(false);
        $trueVal = $context->constantFromBool(true);

        $longBlock = BasicBlockHelper::append($context, 'is_numeric_value_long');
        $doubleBlock = BasicBlockHelper::append($context, 'is_numeric_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'is_numeric_value_string');
        $falseBlock = BasicBlockHelper::append($context, 'is_numeric_value_false');
        $doneBlock = BasicBlockHelper::append($context, 'is_numeric_value_done');

        $afterLong = BasicBlockHelper::append($context, 'is_numeric_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longNumeric = $this->longIsNumeric($context, $longVal);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterDouble = BasicBlockHelper::append($context, 'is_numeric_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterString = BasicBlockHelper::append($context, 'is_numeric_value_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $afterString
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringNumeric = $this->stringIsNumeric($context, $stringVal);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branch($falseBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($trueVal->typeOf());
        $phi->addIncoming($longNumeric, $longEnd);
        $phi->addIncoming($trueVal, $doubleBlock);
        $phi->addIncoming($stringNumeric, $stringEnd);
        $phi->addIncoming($falseVal, $falseBlock);

        return $phi;
    }

}
