<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * String bitwise ops as call-site LLVM (#14823, #24513, #32431, #35301).
 *
 * Unary ~ and binary string⊙string &|^ — NestedJIT of StringBitwiseNotJitHelper into
 * user-script AOT mismatches ABI (`__string__*` vs `__value__*`) / segfaults after
 * c:main_before_php (#32431 leftover of #32407; #35301).
 *
 * php-src: Zend/zend_operators.c bitwise_*_function string/string + unary ~
 */
final class StringBitwiseNot
{
    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__string__bitwiseNot',
    ];

    public static function register(Context $context): void
    {
        $fnType = $context->context->functionType(
            $context->getTypeFromString('__string__*'),
            false,
            $context->getTypeFromString('__string__*')
        );
        $fn = $context->module->addFunction('__string__bitwiseNot', $fnType);
        $fn->addAttributeAtIndex(\PHPLLVM\Attribute::INDEX_FUNCTION, $context->attributes['alwaysinline']);
        $context->registerFunction('__string__bitwiseNot', $fn);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__string__bitwiseNot');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Byte-wise unary ~ (each byte inverted & 0xFF).
     *
     * @see php-src Zend/zend_operators.c bitwise_not_function
     */
    public static function emitUnary(Context $context, Value $str): Variable
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'str_bitwise_not_cont');
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $len = $b->load($b->structGep($str, $map['length']));
        $dataIn = $b->structGep($str, $map['value']);
        $out = $b->call($context->lookupFunction('__string__alloc'), $len);
        $dataOut = $b->structGep($out, $map['value']);

        $iPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $b->store($i64->constInt(0, false), $iPtr);

        $head = BasicBlockHelper::append($context, 'str_bitnot_head');
        $body = BasicBlockHelper::append($context, 'str_bitnot_body');
        $done = BasicBlockHelper::append($context, 'str_bitnot_done');
        $b->branch($head);

        $b->positionAtEnd($head);
        $i = $b->load($iPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $i, $len), $body, $done);

        $b->positionAtEnd($body);
        $byte = $b->load($b->gep($dataIn, $i));
        $b->store($b->not($byte), $b->gep($dataOut, $i));
        $b->store($b->add($i, $i64->constInt(1, false)), $iPtr);
        $b->branch($head);

        $b->positionAtEnd($done);

        return new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $out);
    }

    /**
     * Byte-wise &|^ (AND/XOR length=min, OR length=max + tail copy).
     *
     * @see php-src Zend/zend_operators.c bitwise_and/or/xor_function
     */
    public static function emitBinary(Context $context, int $opType, Value $leftStr, Value $rightStr): Variable
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'str_bitwise_cont');
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $lenL = $b->load($b->structGep($leftStr, $map['length']));
        $lenR = $b->load($b->structGep($rightStr, $map['length']));
        $dataL = $b->structGep($leftStr, $map['value']);
        $dataR = $b->structGep($rightStr, $map['value']);
        $lLtR = $b->icmp(Builder::INT_ULT, $lenL, $lenR);
        $min = $b->select($lLtR, $lenL, $lenR);
        $max = $b->select($lLtR, $lenR, $lenL);
        $isOr = OpCode::TYPE_BITWISE_OR === $opType;
        $outLen = $isOr ? $max : $min;
        $out = $b->call($context->lookupFunction('__string__alloc'), $outLen);
        $dataO = $b->structGep($out, $map['value']);

        $iPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $b->store($i64->constInt(0, false), $iPtr);

        $head = BasicBlockHelper::append($context, 'str_bit_head');
        $body = BasicBlockHelper::append($context, 'str_bit_body');
        $afterMin = BasicBlockHelper::append($context, 'str_bit_after_min');
        $done = BasicBlockHelper::append($context, 'str_bit_done');
        $b->branch($head);

        $b->positionAtEnd($head);
        $i = $b->load($iPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $i, $min), $body, $afterMin);

        $b->positionAtEnd($body);
        $a = $b->load($b->gep($dataL, $i));
        $c = $b->load($b->gep($dataR, $i));
        if (OpCode::TYPE_BITWISE_AND === $opType) {
            $r = $b->bitwiseAnd($a, $c);
        } elseif (OpCode::TYPE_BITWISE_OR === $opType) {
            $r = $b->bitwiseOr($a, $c);
        } else {
            $r = $b->bitwiseXor($a, $c);
        }
        $b->store($r, $b->gep($dataO, $i));
        $b->store($b->add($i, $i64->constInt(1, false)), $iPtr);
        $b->branch($head);

        $b->positionAtEnd($afterMin);
        if ($isOr) {
            $tailHead = BasicBlockHelper::append($context, 'str_bit_tail_head');
            $tailBody = BasicBlockHelper::append($context, 'str_bit_tail_body');
            $b->branch($tailHead);
            $b->positionAtEnd($tailHead);
            $j = $b->load($iPtr);
            $b->branchIf($b->icmp(Builder::INT_ULT, $j, $max), $tailBody, $done);
            $b->positionAtEnd($tailBody);
            $leftLonger = $b->icmp(Builder::INT_UGT, $lenL, $lenR);
            $srcByte = $b->load($b->gep($b->select($leftLonger, $dataL, $dataR), $j));
            $b->store($srcByte, $b->gep($dataO, $j));
            $b->store($b->add($j, $i64->constInt(1, false)), $iPtr);
            $b->branch($tailHead);
        } else {
            $b->branch($done);
        }

        $b->positionAtEnd($done);

        return new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $out);
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__string__bitwiseNot';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('bitwise_not_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // Call-site LLVM body (peer emitBinary #32431) — NestedJIT helper ABI is __value__*
        // while this shell is __string__* (#35301).
        $result = self::emitUnary($context, $fn->getParam(0));
        $context->builder->returnValue($result->value);
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringBitwiseNot bridge (#14823)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
