<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Unary ~ via StringBitwiseNotJitHelper NestedJIT (#14823, #24513).
 * Binary string⊙string &|^ is call-site LLVM — NestedJIT of the helper into
 * user-script AOT segfaults after c:main_before_php (#32431 leftover of #32407).
 *
 * php-src: Zend/zend_operators.c bitwise_*_function string/string + unary ~
 */
final class StringBitwiseNot
{
    private const HELPER_PATH = '/ext/standard/StringBitwiseNotJitHelper.php';

    private const BITWISE_NOT_HELPER = 'PHPCompiler\\ext\\standard\\StringBitwiseNotJitHelper::bitwiseNotArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BITWISE_NOT_HELPER,
    ];

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

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
        $result = $context->builder->call(
            self::helperFunction($context, self::BITWISE_NOT_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24513');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24513'
        );
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
