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

/**
 * Unary ~ via StringBitwiseNotJitHelper NestedJIT (#14823, #24513, #35301).
 * Binary string⊙string &|^ is call-site LLVM — NestedJIT of the helper into
 * user-script AOT segfaults after c:main_before_php (#32431 leftover of #32407).
 *
 * php-src: Zend/zend_operators.c bitwise_*_function string/string + unary ~
 */
final class StringBitwiseNot
{
    private const HELPER_PATH = '/ext/standard/StringBitwiseNotJitHelper.php';

    private const BITWISE_NOT_HELPER = 'PHPCompiler\\ext\\standard\\StringBitwiseNotJitHelper::bitwiseNotArgv';

    private const ABI = '__string__bitwiseNot';

    private const BRIDGE_ENTRY = 'bitwise_not_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BITWISE_NOT_HELPER,
    ];

    public static function register(Context $context): void
    {
        $fnType = $context->context->functionType(
            $context->getTypeFromString('__string__*'),
            false,
            $context->getTypeFromString('__string__*')
        );
        $fn = $context->module->addFunction(self::ABI, $fnType);
        $fn->addAttributeAtIndex(\PHPLLVM\Attribute::INDEX_FUNCTION, $context->attributes['alwaysinline']);
        $context->registerFunction(self::ABI, $fn);
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
        if (\PHPCompiler\JIT\NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
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
        $strPtr = $context->getTypeFromString('__string__*');
        // Coerce ABI __string__* ↔ NestedJIT __value__* (#35301; peer StringHtmlspecialchars #20487).
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::BITWISE_NOT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35301'
        );
    }
}
