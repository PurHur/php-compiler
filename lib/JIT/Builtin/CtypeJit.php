<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\ctype\VmCtype;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM ctype helpers (mirrors ext/ctype/VmCtype.php; php-src ext/ctype/ctype.c; #7253).
 */
final class CtypeJit
{
    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        if (self::alreadyImplemented($context, '__phpc_ctype_check_char')) {
            self::registerAll($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_ctype_check_char', self::emitCheckChar(...));
        self::implementIfMissing($context, '__phpc_ctype_check_string', self::emitCheckString(...));
        self::implementIfMissing($context, '__phpc_ctype_check_long', self::emitCheckLong(...));
        self::implementIfMissing($context, '__phpc_ctype_from_value', self::emitFromValue(...));

        self::restoreInsertBlock($context, $restore);
    }

    private static function alreadyImplemented(Context $context, string $name): bool
    {
        $probe = $context->module->getNamedFunction($name);

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function registerAll(Context $context): void
    {
        foreach ([
            '__phpc_ctype_check_char',
            '__phpc_ctype_check_string',
            '__phpc_ctype_check_long',
            '__phpc_ctype_from_value',
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');

        return match ($name) {
            '__phpc_ctype_check_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8, $i8)
            ),
            '__phpc_ctype_check_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i64, $i8)
            ),
            '__phpc_ctype_check_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64, $i8, $i8, $i8)
            ),
            '__phpc_ctype_from_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $valuePtr, $i8, $i8, $i8)
            ),
            default => throw new \LogicException('Unknown ctype JIT helper: '.$name),
        };
    }

    private static function emitCheckChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $c = $fn->getParam(0);
        $kind = $fn->getParam(1);
        $one = $i32->constInt(1, false);
        $zero = $i32->constInt(0, false);

        $defaultBlock = $fn->appendBasicBlock('kind_default');
        $cursor = $entry;
        for ($i = 0; $i <= VmCtype::KIND_XDIGIT; ++$i) {
            $caseBlock = $fn->appendBasicBlock('kind_'.$i);
            $afterBlock = $fn->appendBasicBlock('after_kind_'.$i);
            $context->builder->positionAtEnd($cursor);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $kind, $kind->typeOf()->constInt($i, false)),
                $caseBlock,
                $afterBlock
            );
            $context->builder->positionAtEnd($caseBlock);
            $match = self::emitCharPredicate($context, $c, $i);
            $context->builder->returnValue($context->builder->select($match, $one, $zero));
            $cursor = $afterBlock;
        }

        $context->builder->positionAtEnd($cursor);
        $context->builder->branch($defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->returnValue($zero);
    }

    private static function emitCharPredicate(Context $context, Value $c, int $kind): Value
    {
        return match ($kind) {
            VmCtype::KIND_ALNUM => $context->builder->or(self::emitIsDigit($context, $c), self::emitIsAlpha($context, $c)),
            VmCtype::KIND_ALPHA => self::emitIsAlpha($context, $c),
            VmCtype::KIND_CNTRL => self::emitIsCntrl($context, $c),
            VmCtype::KIND_DIGIT => self::emitIsDigit($context, $c),
            VmCtype::KIND_LOWER => self::emitIsLower($context, $c),
            VmCtype::KIND_GRAPH => $context->builder->and(self::emitIsPrint($context, $c), $context->builder->not(self::emitIsSpace($context, $c))),
            VmCtype::KIND_PRINT => self::emitIsPrint($context, $c),
            VmCtype::KIND_PUNCT => $context->builder->and(
                self::emitIsPrint($context, $c),
                $context->builder->and(
                    $context->builder->not(self::emitIsAlnum($context, $c)),
                    $context->builder->not(self::emitIsSpace($context, $c))
                )
            ),
            VmCtype::KIND_SPACE => self::emitIsSpace($context, $c),
            VmCtype::KIND_UPPER => self::emitIsUpper($context, $c),
            VmCtype::KIND_XDIGIT => self::emitIsXdigit($context, $c),
            default => $context->getTypeFromString('int1')->constInt(0, false),
        };
    }

    private static function emitCheckString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $data = $fn->getParam(0);
        $len = $fn->getParam(1);
        $kind = $fn->getParam(2);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);

        $emptyBlock = $fn->appendBasicBlock('empty');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $failBlock = $fn->appendBasicBlock('fail');
        $okBlock = $fn->appendBasicBlock('ok');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero64);
        $context->builder->branchIf($isEmpty, $emptyBlock, $loopHead);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->returnValue($zero32);

        $context->builder->positionAtEnd($loopHead);
        $idxPhi = $context->builder->phi($i64, 'idx');
        $idxPhi->addIncoming($zero64, $entry);
        $done = $context->builder->icmp(Builder::INT_SGE, $idxPhi, $len);
        $context->builder->branchIf($done, $okBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ptr = $context->builder->gep($data, $idxPhi);
        $ch = $context->builder->load($ptr);
        $checkFn = $context->lookupFunction('__phpc_ctype_check_char');
        $matches = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($checkFn, $ch, $kind),
            $zero32
        );
        $nextIdx = $context->builder->addNoSignedWrap($idxPhi, $one64);
        $context->builder->branchIf($matches, $loopHead, $failBlock);
        $idxPhi->addIncoming($nextIdx, $loopBody);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($zero32);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->returnValue($one32);
    }

    private static function emitCheckLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $val = $fn->getParam(0);
        $kind = $fn->getParam(1);
        $allowDigits = $fn->getParam(2);
        $allowMinus = $fn->getParam(3);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);
        $zero64 = $i64->constInt(0, false);
        $maxByte = $i64->constInt(255, false);
        $minNeg = $i64->constInt(-128, false);
        $negOne = $i64->constInt(-1, false);
        $byteOffset = $i64->constInt(256, false);

        $range0Block = $fn->appendBasicBlock('range_0_255');
        $afterRange0 = $fn->appendBasicBlock('after_range_0');
        $rangeNegBlock = $fn->appendBasicBlock('range_neg');
        $posFallbackBlock = $fn->appendBasicBlock('pos_fallback');
        $negFallbackBlock = $fn->appendBasicBlock('neg_fallback');

        $inRange0 = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $val, $zero64),
            $context->builder->icmp(Builder::INT_SLE, $val, $maxByte)
        );
        $context->builder->branchIf($inRange0, $range0Block, $afterRange0);

        $context->builder->positionAtEnd($range0Block);
        $byte0 = $context->builder->truncOrBitCast($val, $i8);
        $checkFn = $context->lookupFunction('__phpc_ctype_check_char');
        $context->builder->returnValue($context->builder->call($checkFn, $byte0, $kind));

        $context->builder->positionAtEnd($afterRange0);
        $inNeg = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $val, $minNeg),
            $context->builder->icmp(Builder::INT_SLT, $val, $zero64)
        );
        $context->builder->branchIf($inNeg, $rangeNegBlock, $posFallbackBlock);

        $context->builder->positionAtEnd($rangeNegBlock);
        $adj = $context->builder->addNoSignedWrap($val, $byteOffset);
        $byteNeg = $context->builder->truncOrBitCast($adj, $i8);
        $context->builder->returnValue($context->builder->call($checkFn, $byteNeg, $kind));

        $posResultBlock = $fn->appendBasicBlock('pos_result');

        $context->builder->positionAtEnd($posFallbackBlock);
        $isPos = $context->builder->icmp(Builder::INT_SGE, $val, $zero64);
        $context->builder->branchIf($isPos, $posResultBlock, $negFallbackBlock);

        $context->builder->positionAtEnd($posResultBlock);
        $context->builder->returnValue($context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $allowDigits, $i8->constInt(0, false)),
            $one32,
            $zero32
        ));

        $context->builder->positionAtEnd($negFallbackBlock);
        $context->builder->returnValue($context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $allowMinus, $i8->constInt(0, false)),
            $one32,
            $zero32
        ));
    }

    private static function emitFromValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $valuePtr = $fn->getParam(0);
        $kind = $fn->getParam(1);
        $allowDigits = $fn->getParam(2);
        $allowMinus = $fn->getParam(3);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero32 = $i32->constInt(0, false);
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $stringBlock = BasicBlockHelper::append($context, 'ctype_value_string');
        $longBlock = BasicBlockHelper::append($context, 'ctype_value_long');
        $falseBlock = BasicBlockHelper::append($context, 'ctype_value_false');
        $doneBlock = BasicBlockHelper::append($context, 'ctype_value_done');

        $afterStringCheck = BasicBlockHelper::append($context, 'ctype_value_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeKind, $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)),
            $stringBlock,
            $afterStringCheck
        );

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strData = $context->builder->structGep(
            $strPtr,
            $context->structFieldMap[$context->structNameForValue($strPtr)]['value']
        );
        $strLen = $context->builder->call($context->lookupFunction('__string__strlen'), $strPtr);
        $stringResult = $context->builder->call(
            $context->lookupFunction('__phpc_ctype_check_string'),
            $strData,
            $strLen,
            $kind
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterStringCheck);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeKind, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $falseBlock
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longResult = $context->builder->call(
            $context->lookupFunction('__phpc_ctype_check_long'),
            $longVal,
            $kind,
            $allowDigits,
            $allowMinus
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i32, 'ctype_value_result');
        $phi->addIncoming($stringResult, $stringEnd);
        $phi->addIncoming($longResult, $longEnd);
        $phi->addIncoming($zero32, $falseBlock);
        $context->builder->returnValue($phi);
    }

    private static function emitIsAlnum(Context $context, Value $c): Value
    {
        return $context->builder->or(self::emitIsDigit($context, $c), self::emitIsAlpha($context, $c));
    }

    private static function emitIsAlpha(Context $context, Value $c): Value
    {
        return $context->builder->or(self::emitIsLower($context, $c), self::emitIsUpper($context, $c));
    }

    private static function emitIsDigit(Context $context, Value $c): Value
    {
        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $c->typeOf()->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $c->typeOf()->constInt(57, false))
        );
    }

    private static function emitIsLower(Context $context, Value $c): Value
    {
        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $c->typeOf()->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $c->typeOf()->constInt(122, false))
        );
    }

    private static function emitIsUpper(Context $context, Value $c): Value
    {
        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $c->typeOf()->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $c->typeOf()->constInt(90, false))
        );
    }

    private static function emitIsXdigit(Context $context, Value $c): Value
    {
        return $context->builder->or(
            self::emitIsDigit($context, $c),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $c, $c->typeOf()->constInt(65, false)),
                    $context->builder->icmp(Builder::INT_SLE, $c, $c->typeOf()->constInt(70, false))
                ),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $c, $c->typeOf()->constInt(97, false)),
                    $context->builder->icmp(Builder::INT_SLE, $c, $c->typeOf()->constInt(102, false))
                )
            )
        );
    }

    private static function emitIsCntrl(Context $context, Value $c): Value
    {
        return $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $c, $c->typeOf()->constInt(32, false)),
            $context->builder->icmp(Builder::INT_EQ, $c, $c->typeOf()->constInt(127, false))
        );
    }

    private static function emitIsSpace(Context $context, Value $c): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isTab = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(9, false));
        $isNl = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(10, false));
        $isVt = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(11, false));
        $isFf = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(12, false));
        $isCr = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(13, false));
        $isSp = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(32, false));

        return $context->builder->or(
            $isTab,
            $context->builder->or(
                $isNl,
                $context->builder->or($isVt, $context->builder->or($isFf, $context->builder->or($isCr, $isSp)))
            )
        );
    }

    private static function emitIsPrint(Context $context, Value $c): Value
    {
        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $c->typeOf()->constInt(32, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $c->typeOf()->constInt(126, false))
        );
    }

    /**
     * @return array{0: ?BasicBlock, 1: ?Value}
     */
    private static function captureInsertBlock(Context $context): array
    {
        try {
            $block = $context->builder->getInsertBlock();
        } catch (\TypeError) {
            $block = null;
        }

        return [$block, null !== $block ? $block->getParent() : null];
    }

    /**
     * @param array{0: ?BasicBlock, 1: ?Value} $restore
     */
    private static function restoreInsertBlock(Context $context, array $restore): void
    {
        [$block] = $restore;
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
