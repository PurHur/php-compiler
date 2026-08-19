<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SpaceshipRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM spaceship (<=>) dispatch for boxed values, objects, and hashtables (#5185, #9381, #19623).
 *
 * Quarantined from lib/JIT/Builtin/SpaceshipCompareJit — {@see \PHPCompiler\JIT\Builtin\SpaceshipRuntime}
 * stays the thin orchestrator.
 *
 * Scalar compare semantics route through NestedJIT {@see \PHPCompiler\VM\CompareJitHelperScalars};
 * object/hashtable walks use LLVM emitters (NestedJIT ObjectEntry/HashTable IR is unsafe — #21109).
 *
 * php-src: Zend/zend_operators.c — compare_function / spaceship
 */
final class JitSpaceshipCompareKernel
{
    private const TYPE_NULL = 0;

    private const TYPE_LONG = 1;

    private const TYPE_BOOL = 2;

    private const TYPE_DOUBLE = 3;

    private const TYPE_STRING = 4;

    private const TYPE_OBJECT = 5;

    private const TYPE_HASHTABLE = 7;

    private const TYPEINFO_TYPEMASK = 0xFFFFFFFC;

    private const TYPEINFO_TYPE_STRING = 4;

    private const TYPEINFO_TYPE_OBJECT = 8;

    private static int $blockSuffix = 0;

    /**
     * Forward-declare spaceship ABI symbols before CompareJitHelperScalars nested compile (#19048/#21109).
     *
     * @return array{0: LlvmFunction, 1: LlvmFunction, 2: LlvmFunction}
     */
    public static function declareAbiFunctions(Context $context): array
    {
        self::ensureExternals($context);

        $valueFn = self::declareFunction(
            $context,
            '__value__spaceship',
            $context->getTypeFromString('int64'),
            [
                $context->getTypeFromString('__value__*'),
                $context->getTypeFromString('__value__*'),
            ]
        );
        $objectFn = self::declareFunction(
            $context,
            '__object__compareSpaceship',
            $context->getTypeFromString('int64'),
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__object__*'),
            ]
        );
        $htFn = self::declareFunction(
            $context,
            '__hashtable__compareSpaceship',
            $context->getTypeFromString('int64'),
            [
                $context->getTypeFromString('__hashtable__*'),
                $context->getTypeFromString('__hashtable__*'),
            ]
        );

        return [$valueFn, $objectFn, $htFn];
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::$blockSuffix = 0;

        [$valueFn, $objectFn, $htFn] = self::declareAbiFunctions($context);

        // Helper-runtime / NestedJIT may already have __value__spaceship with a body
        // while __hashtable__compareSpaceship is still a declaration. Skipping the
        // whole kernel left hashtable <=> as an AOT jump-to-null (#32536 leftover of
        // #32524). Emit each ABI independently.
        if (0 === $htFn->countBasicBlocks()) {
            self::emitHashtableCompareSpaceship($context, $htFn);
        }
        if (0 === $objectFn->countBasicBlocks()) {
            self::emitObjectCompareSpaceship($context, $objectFn);
        }
        if (0 === $valueFn->countBasicBlocks()) {
            self::emitValueSpaceship($context, $valueFn);
        }

        self::registerFunctions($context);
        self::restoreInsertBlock($context, $restore);
    }

    private static function registerFunctions(Context $context): void
    {
        foreach (['__value__spaceship', '__object__compareSpaceship', '__hashtable__compareSpaceship'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $dbl = $context->getTypeFromString('double');
        $dblPtr = $context->getTypeFromString('double*');
        $objPtr = $context->getTypeFromString('__object__*');

        self::declareExternal($context, 'strcmp', $i32, [$i8p, $i8p]);
        self::declareExternal($context, 'strncasecmp', $i32, [$i8p, $i8p, $sizeT]);
        self::declareExternal($context, 'strtod', $dbl, [$i8p, $i8p->pointerType(0)]);
        self::declareExternal($context, '__object__prop_count', $i32, [$objPtr]);
        // memcmp(3) via LibcExtern::ensureMemcmpDecl after always-on drop (#31954);
        // canonical i8* ABI avoids void* NestedJIT mistyped calls (#27663).
        LibcExtern::ensureMemcmpDecl($context);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function declareExternal(
        Context $context,
        string $name,
        \PHPLLVM\Type $returnType,
        array $paramTypes
    ): void {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($returnType, false, ...$paramTypes)
            );
            $context->registerFunction($name, $fn);
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function declareFunction(
        Context $context,
        string $name,
        \PHPLLVM\Type $returnType,
        array $paramTypes
    ): LlvmFunction {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($returnType, false, ...$paramTypes)
            );
            $context->registerFunction($name, $fn);

            return $fn;
        }
    }

    private static function emitValueSpaceship(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_val_entry');
        $context->builder->positionAtEnd($entry);

        $left = $fn->getParam(0);
        $right = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $valuePtr = $context->getTypeFromString('__value__*');
        $nullPtr = $valuePtr->constNull();

        $leftNull = $context->builder->icmp(Builder::INT_EQ, $left, $nullPtr);
        $rightNull = $context->builder->icmp(Builder::INT_EQ, $right, $nullPtr);
        $eitherNull = $context->builder->or($leftNull, $rightNull);
        $nullRet = $fn->appendBasicBlock('ss_val_null_ret');
        $work = $fn->appendBasicBlock('ss_val_work');
        $context->builder->branchIf($eitherNull, $nullRet, $work);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($work);
        $lkind = self::valueKind($context, $left);
        $rkind = self::valueKind($context, $right);
        $kindsEq = $context->builder->icmp(Builder::INT_EQ, $lkind, $rkind);
        $sameKind = $fn->appendBasicBlock('ss_val_same_kind');
        $mixed = $fn->appendBasicBlock('ss_val_mixed');
        $context->builder->branchIf($kindsEq, $sameKind, $mixed);

        $context->builder->positionAtEnd($sameKind);
        self::emitSameKindSpaceship($context, $fn, $left, $right, $lkind, $mixed);

        $context->builder->positionAtEnd($mixed);
        self::emitMixedSpaceship($context, $fn, $left, $right, $lkind, $rkind);
        $context->builder->clearInsertionPosition();
    }

    private static function emitSameKindSpaceship(
        Context $context,
        LlvmFunction $fn,
        Value $left,
        Value $right,
        Value $kind,
        BasicBlock $fallback
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $cases = [
            self::TYPE_NULL => fn () => $i64->constInt(0, false),
            self::TYPE_BOOL => fn () => self::longSpaceship(
                $context,
                self::readBoolAsLong($context, $left),
                self::readBoolAsLong($context, $right)
            ),
            self::TYPE_LONG => fn () => self::longSpaceship(
                $context,
                $context->builder->call($context->lookupFunction('__value__readLong'), $left),
                $context->builder->call($context->lookupFunction('__value__readLong'), $right)
            ),
            self::TYPE_DOUBLE => fn () => self::doubleSpaceship(
                $context,
                $context->builder->call($context->lookupFunction('__value__readDouble'), $left),
                $context->builder->call($context->lookupFunction('__value__readDouble'), $right)
            ),
            self::TYPE_STRING => fn () => self::i64FromI32(
                $context,
                self::stringSpaceship(
                    $context,
                    $context->builder->call($context->lookupFunction('__value__readString'), $left),
                    $context->builder->call($context->lookupFunction('__value__readString'), $right)
                )
            ),
            self::TYPE_HASHTABLE => fn () => $context->builder->call(
                $context->lookupFunction('__hashtable__compareSpaceship'),
                $context->builder->call($context->lookupFunction('__value__readHashtable'), $left),
                $context->builder->call($context->lookupFunction('__value__readHashtable'), $right)
            ),
            self::TYPE_OBJECT => fn () => $context->builder->call(
                $context->lookupFunction('__object__compareSpaceship'),
                $context->builder->call($context->lookupFunction('__value__readObject'), $left),
                $context->builder->call($context->lookupFunction('__value__readObject'), $right)
            ),
        ];

        $check = $context->builder->getInsertBlock();
        foreach ($cases as $typeConst => $emit) {
            $match = $fn->appendBasicBlock(self::blockName('ss_val_kind_'.$typeConst));
            $next = $fn->appendBasicBlock(self::blockName('ss_val_try_'.$typeConst));
            $context->builder->positionAtEnd($check);
            $isKind = $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i32->constInt($typeConst, false)
            );
            $context->builder->branchIf($isKind, $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue($emit());
            $check = $next;
        }

        $context->builder->positionAtEnd($check);
        $context->builder->branch($fallback);
    }

    private static function emitMixedSpaceship(
        Context $context,
        LlvmFunction $fn,
        Value $left,
        Value $right,
        Value $lkind,
        Value $rkind
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $checks = [
            [self::TYPE_BOOL, self::TYPE_STRING, fn () => self::longSpaceship(
                $context,
                self::readBoolAsLong($context, $left),
                self::i64FromI32($context, self::stringToBool($context, self::readString($context, $right)))
            )],
            [self::TYPE_STRING, self::TYPE_BOOL, fn () => self::longSpaceship(
                $context,
                self::i64FromI32($context, self::stringToBool($context, self::readString($context, $left))),
                self::readBoolAsLong($context, $right)
            )],
            [self::TYPE_NULL, self::TYPE_STRING, fn () => self::emitNullStringMixed($context, $right, true)],
            [self::TYPE_STRING, self::TYPE_NULL, fn () => self::negateI64(
                $context,
                self::emitNullStringMixed($context, $left, true)
            )],
            [self::TYPE_LONG, self::TYPE_STRING, fn () => self::spaceshipNumberString(
                $context,
                $context->builder->sitofp($context->builder->call($context->lookupFunction('__value__readLong'), $left), $context->getTypeFromString('double')),
                self::readString($context, $right),
                true
            )],
            [self::TYPE_STRING, self::TYPE_LONG, fn () => self::negateI64(
                $context,
                self::spaceshipNumberString(
                    $context,
                    $context->builder->sitofp(
                        $context->builder->call($context->lookupFunction('__value__readLong'), $right),
                        $context->getTypeFromString('double')
                    ),
                    self::readString($context, $left),
                    true
                )
            )],
            [self::TYPE_DOUBLE, self::TYPE_STRING, fn () => self::spaceshipNumberString(
                $context,
                $context->builder->call($context->lookupFunction('__value__readDouble'), $left),
                self::readString($context, $right),
                true
            )],
            [self::TYPE_STRING, self::TYPE_DOUBLE, fn () => self::negateI64(
                $context,
                self::spaceshipNumberString(
                    $context,
                    $context->builder->call($context->lookupFunction('__value__readDouble'), $right),
                    self::readString($context, $left),
                    true
                )
            )],
            // zend_compare: IS_ARRAY vs IS_NULL/IS_FALSE uses zend_is_true
            // (php-src Zend/zend_operators.c compare_function; leftover of #32536/#32539).
            [self::TYPE_HASHTABLE, self::TYPE_NULL, fn () => self::hashtableTruthySpaceship($context, $left, true)],
            [self::TYPE_NULL, self::TYPE_HASHTABLE, fn () => self::hashtableTruthySpaceship($context, $right, false)],
            [self::TYPE_HASHTABLE, self::TYPE_BOOL, fn () => self::nativeI64Spaceship(
                $context,
                self::hashtableTruthyI64($context, $left),
                self::readBoolAsLong($context, $right)
            )],
            [self::TYPE_BOOL, self::TYPE_HASHTABLE, fn () => self::nativeI64Spaceship(
                $context,
                self::readBoolAsLong($context, $left),
                self::hashtableTruthyI64($context, $right)
            )],
        ];

        $check = $context->builder->getInsertBlock();
        foreach ($checks as [$lk, $rk, $emit]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_next'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt($lk, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt($rk, false));
            $both = $context->builder->and($lMatch, $rMatch);
            $context->builder->branchIf($both, $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue($emit());
            $check = $next;
        }

        self::emitBoolNumberMixed($context, $fn, $left, $right, $lkind, $rkind, $check);
    }

    private static function emitBoolNumberMixed(
        Context $context,
        LlvmFunction $fn,
        Value $left,
        Value $right,
        Value $lkind,
        Value $rkind,
        BasicBlock $check
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $zeroDbl = $dbl->constReal(0.0);

        $boolLeftCases = [
            [self::TYPE_LONG, fn () => $context->builder->sitofp(
                $context->builder->call($context->lookupFunction('__value__readLong'), $right),
                $dbl
            )],
            [self::TYPE_DOUBLE, fn () => $context->builder->call($context->lookupFunction('__value__readDouble'), $right)],
            [self::TYPE_NULL, fn () => $zeroDbl],
        ];
        foreach ($boolLeftCases as [$rk, $readRightNum]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix_bl'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_bl_n'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt(self::TYPE_BOOL, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt($rk, false));
            $context->builder->branchIf($context->builder->and($lMatch, $rMatch), $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue(
                self::longSpaceship($context, self::readBoolAsLong($context, $left), self::doubleToLong($context, $readRightNum()))
            );
            $check = $next;
        }

        $boolRightCases = [
            [self::TYPE_LONG, fn () => $context->builder->sitofp(
                $context->builder->call($context->lookupFunction('__value__readLong'), $left),
                $dbl
            )],
            [self::TYPE_DOUBLE, fn () => $context->builder->call($context->lookupFunction('__value__readDouble'), $left)],
            [self::TYPE_NULL, fn () => $zeroDbl],
        ];
        foreach ($boolRightCases as [$lk, $readLeftNum]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix_br'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_br_n'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt($lk, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt(self::TYPE_BOOL, false));
            $context->builder->branchIf($context->builder->and($lMatch, $rMatch), $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue(
                self::longSpaceship($context, self::doubleToLong($context, $readLeftNum()), self::readBoolAsLong($context, $right))
            );
            $check = $next;
        }

        $nullLeftCases = [
            [self::TYPE_LONG, fn () => $context->builder->sitofp(
                $context->builder->call($context->lookupFunction('__value__readLong'), $right),
                $dbl
            )],
            [self::TYPE_DOUBLE, fn () => $context->builder->call($context->lookupFunction('__value__readDouble'), $right)],
        ];
        foreach ($nullLeftCases as [$rk, $readRightNum]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix_nl'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_nl_n'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt(self::TYPE_NULL, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt($rk, false));
            $context->builder->branchIf($context->builder->and($lMatch, $rMatch), $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue(self::doubleSpaceship($context, $zeroDbl, $readRightNum()));
            $check = $next;
        }

        $nullRightCases = [
            [self::TYPE_LONG, fn () => $context->builder->sitofp(
                $context->builder->call($context->lookupFunction('__value__readLong'), $left),
                $dbl
            )],
            [self::TYPE_DOUBLE, fn () => $context->builder->call($context->lookupFunction('__value__readDouble'), $left)],
        ];
        foreach ($nullRightCases as [$lk, $readLeftNum]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix_nr'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_nr_n'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt($lk, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt(self::TYPE_NULL, false));
            $context->builder->branchIf($context->builder->and($lMatch, $rMatch), $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue(self::doubleSpaceship($context, $readLeftNum(), $zeroDbl));
            $check = $next;
        }

        $longDoubleCases = [
            [self::TYPE_LONG, self::TYPE_DOUBLE, fn () => self::doubleSpaceship(
                $context,
                $context->builder->sitofp(
                    $context->builder->call($context->lookupFunction('__value__readLong'), $left),
                    $dbl
                ),
                $context->builder->call($context->lookupFunction('__value__readDouble'), $right)
            )],
            [self::TYPE_DOUBLE, self::TYPE_LONG, fn () => self::doubleSpaceship(
                $context,
                $context->builder->call($context->lookupFunction('__value__readDouble'), $left),
                $context->builder->sitofp(
                    $context->builder->call($context->lookupFunction('__value__readLong'), $right),
                    $dbl
                )
            )],
        ];
        foreach ($longDoubleCases as [$lk, $rk, $emit]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix_ld'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_ld_n'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt($lk, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt($rk, false));
            $context->builder->branchIf($context->builder->and($lMatch, $rMatch), $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue($emit());
            $check = $next;
        }

        $objStringCases = [
            [self::TYPE_OBJECT, self::TYPE_STRING, fn () => self::objectEnumVsString($context, $left, true)],
            [self::TYPE_STRING, self::TYPE_OBJECT, fn () => self::objectEnumVsString($context, $right, false)],
        ];
        foreach ($objStringCases as [$lk, $rk, $emit]) {
            $match = $fn->appendBasicBlock(self::blockName('ss_mix_os'));
            $next = $fn->appendBasicBlock(self::blockName('ss_mix_os_n'));
            $context->builder->positionAtEnd($check);
            $lMatch = $context->builder->icmp(Builder::INT_EQ, $lkind, $i32->constInt($lk, false));
            $rMatch = $context->builder->icmp(Builder::INT_EQ, $rkind, $i32->constInt($rk, false));
            $context->builder->branchIf($context->builder->and($lMatch, $rMatch), $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue($emit());
            $check = $next;
        }

        $context->builder->positionAtEnd($check);
        $context->builder->returnValue(
            self::kindSpaceship(
                $context,
                self::i64FromI32($context, $lkind),
                self::i64FromI32($context, $rkind)
            )
        );
    }

    private static function emitNullStringMixed(Context $context, Value $value, bool $numOnLeft): Value
    {
        $str = self::readString($context, $value);
        $len = self::stringLen($context, $str);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        return $context->builder->select(
            $isEmpty,
            $i64->constInt(0, false),
            self::spaceshipNumberString(
                $context,
                $context->getTypeFromString('double')->constReal(0.0),
                $str,
                $numOnLeft
            )
        );
    }

    private static function objectEnumVsString(Context $context, Value $value, bool $objectOnLeft): Value
    {
        $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $value);
        $caseName = self::objectCaseNameSlot($context, $obj, 0);
        $strPtr = $context->getTypeFromString('__string__*');
        $isEnum = $context->builder->icmp(Builder::INT_NE, $caseName, $strPtr->constNull());
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, true);

        return $context->builder->select(
            $isEnum,
            $one,
            self::longSpaceship(
                $context,
                $i64->constInt(self::TYPE_OBJECT, false),
                $i64->constInt(self::TYPE_STRING, false)
            )
        );
    }

    private static function emitObjectCompareSpaceship(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_obj_entry');
        $context->builder->positionAtEnd($entry);

        $left = $fn->getParam(0);
        $right = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $objPtr = $context->getTypeFromString('__object__*');
        $nullObj = $objPtr->constNull();

        $samePtr = $context->builder->icmp(Builder::INT_EQ, $left, $right);
        $sameRet = $fn->appendBasicBlock('ss_obj_same');
        $notSame = $fn->appendBasicBlock('ss_obj_not_same');
        $context->builder->branchIf($samePtr, $sameRet, $notSame);

        $context->builder->positionAtEnd($sameRet);
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($notSame);
        $leftNull = $context->builder->icmp(Builder::INT_EQ, $left, $nullObj);
        $rightNull = $context->builder->icmp(Builder::INT_EQ, $right, $nullObj);
        $eitherNull = $context->builder->or($leftNull, $rightNull);
        $nullCmp = $fn->appendBasicBlock('ss_obj_null_cmp');
        $enumTry = $fn->appendBasicBlock('ss_obj_enum_try');
        $context->builder->branchIf($eitherNull, $nullCmp, $enumTry);

        $context->builder->positionAtEnd($nullCmp);
        $leftKind = $context->builder->select(
            $leftNull,
            $i64->constInt(self::TYPE_NULL, false),
            $i64->constInt(self::TYPE_OBJECT, false)
        );
        $rightKind = $context->builder->select(
            $rightNull,
            $i64->constInt(self::TYPE_NULL, false),
            $i64->constInt(self::TYPE_OBJECT, false)
        );
        $context->builder->returnValue(self::longSpaceship($context, $leftKind, $rightKind));

        $context->builder->positionAtEnd($enumTry);
        $propCmp = $fn->appendBasicBlock('ss_obj_prop_cmp');
        self::emitEnumCaseTry($context, $fn, $left, $right, $propCmp);

        $context->builder->positionAtEnd($propCmp);
        $objMap = $context->structFieldMap['__object__'];
        $leftClass = $context->builder->load($context->builder->structGep($left, $objMap['class_id']));
        $rightClass = $context->builder->load($context->builder->structGep($right, $objMap['class_id']));
        $classDiff = $context->builder->icmp(Builder::INT_NE, $leftClass, $rightClass);
        $classRet = $fn->appendBasicBlock('ss_obj_class_diff');
        $propLoopInit = $fn->appendBasicBlock('ss_obj_prop_init');
        $context->builder->branchIf($classDiff, $classRet, $propLoopInit);

        $context->builder->positionAtEnd($classRet);
        $context->builder->returnValue($i64->constInt(1, true));

        $context->builder->positionAtEnd($propLoopInit);
        $propCount = $context->builder->call(
            $context->lookupFunction('__object__prop_count'),
            $left
        );
        $slotSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int32'));
        $context->builder->store($propCount, $slotSlot);
        $headerSize = self::objectHeaderSize($context);

        $loopHead = $fn->appendBasicBlock('ss_obj_prop_head');
        $loopDone = $fn->appendBasicBlock('ss_obj_prop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $slot = $context->builder->load($slotSlot);
        $slotDone = $context->builder->icmp(Builder::INT_SGE, $slot, $propCount);
        $loopBody = $fn->appendBasicBlock('ss_obj_prop_body');
        $context->builder->branchIf($slotDone, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $slotIdx = $context->builder->zExt($slot, $context->getTypeFromString('size_t'));
        $slotBytes = $context->builder->mul(
            $slotIdx,
            $context->getTypeFromString('size_t')->constInt(8, false)
        );
        $lSlotPtr = self::propertySlotPtr($context, $left, $headerSize, $slotBytes);
        $rSlotPtr = self::propertySlotPtr($context, $right, $headerSize, $slotBytes);
        $lval = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $rval = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        self::slotContentToValue($context, $context->builder->load($lSlotPtr), $lval);
        self::slotContentToValue($context, $context->builder->load($rSlotPtr), $rval);
        $cmp = $context->builder->call(
            $context->lookupFunction('__value__spaceship'),
            $lval,
            $rval
        );
        $nonZero = $context->builder->icmp(Builder::INT_NE, $cmp, $zero64);
        $retCmp = $fn->appendBasicBlock('ss_obj_prop_ret');
        $advance = $fn->appendBasicBlock('ss_obj_prop_advance');
        $context->builder->branchIf($nonZero, $retCmp, $advance);

        $context->builder->positionAtEnd($retCmp);
        $context->builder->returnValue($cmp);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->add($slot, $context->getTypeFromString('int32')->constInt(1, false)),
            $slotSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($zero64);
        $context->builder->clearInsertionPosition();
    }

    private static function emitEnumCaseTry(
        Context $context,
        LlvmFunction $fn,
        Value $left,
        Value $right,
        BasicBlock $propCmp
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, true);
        $zero = $i64->constInt(0, false);

        $lprops = $context->builder->call(
            $context->lookupFunction('__object__prop_count'),
            $left
        );
        $rprops = $context->builder->call(
            $context->lookupFunction('__object__prop_count'),
            $right
        );
        $propsMatch = $context->builder->icmp(Builder::INT_EQ, $lprops, $rprops);
        $isUnit = $context->builder->icmp(Builder::INT_EQ, $lprops, $i32->constInt(0, false));
        $isBackedShape = $context->builder->icmp(Builder::INT_EQ, $lprops, $i32->constInt(2, false));
        $propsZeroOrTwo = $context->builder->or($isUnit, $isBackedShape);
        $validProps = $context->builder->and($propsMatch, $propsZeroOrTwo);
        $nameCheck = $fn->appendBasicBlock('ss_enum_name_check');
        $context->builder->branchIf($validProps, $nameCheck, $propCmp);

        $context->builder->positionAtEnd($nameCheck);
        // Unit enums (prop_count 0): name slots may not be visible to typeinfo probes under
        // MCJIT; distinct singletons of the same class are different cases (Zend #21124).
        $unitSameClass = $fn->appendBasicBlock('ss_enum_unit_same_class');
        $backedName = $fn->appendBasicBlock('ss_enum_backed_name');
        $context->builder->branchIf($isUnit, $unitSameClass, $backedName);

        $context->builder->positionAtEnd($unitSameClass);
        // Pointers already differ (samePtr returned earlier). Zend <=> is 1 for unequal cases.
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($backedName);
        $lname = self::objectCaseNameSlot($context, $left, 0);
        $rname = self::objectCaseNameSlot($context, $right, 0);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $namesOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $lname, $nullStr),
            $context->builder->icmp(Builder::INT_NE, $rname, $nullStr)
        );
        $classCheck = $fn->appendBasicBlock('ss_enum_class_check');
        $context->builder->branchIf($namesOk, $classCheck, $propCmp);

        $context->builder->positionAtEnd($classCheck);
        $objMap = $context->structFieldMap['__object__'];
        $lClass = $context->builder->load($context->builder->structGep($left, $objMap['class_id']));
        $rClass = $context->builder->load($context->builder->structGep($right, $objMap['class_id']));
        $classDiff = $fn->appendBasicBlock('ss_enum_class_diff');
        $nameCmp = $fn->appendBasicBlock('ss_enum_name_cmp');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $lClass, $rClass),
            $nameCmp,
            $classDiff
        );

        $context->builder->positionAtEnd($classDiff);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($nameCmp);
        $llen = self::stringLen($context, $lname);
        $rlen = self::stringLen($context, $rname);
        $lenDiff = $fn->appendBasicBlock('ss_enum_len_diff');
        $caseCmp = $fn->appendBasicBlock('ss_enum_case_cmp');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $llen, $rlen),
            $caseCmp,
            $lenDiff
        );

        $context->builder->positionAtEnd($lenDiff);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($caseCmp);
        $ldata = self::stringData($context, $lname);
        $rdata = self::stringData($context, $rname);
        // memcmp is module-local after LibcExtern always-on drop (#31954);
        // strncasecmp often relocates to null under EMBED MCJIT (#21124).
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $ldata,
            $rdata,
            $llen
        );
        $isEqual = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->returnValue($context->builder->select($isEqual, $zero, $one));
    }

    private static function emitHashtableCompareSpaceship(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_ht_entry');
        $context->builder->positionAtEnd($entry);

        $left = $fn->getParam(0);
        $right = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();

        $leftNull = $context->builder->icmp(Builder::INT_EQ, $left, $nullHt);
        $rightNull = $context->builder->icmp(Builder::INT_EQ, $right, $nullHt);
        $eitherNull = $context->builder->or($leftNull, $rightNull);
        $nullRet = $fn->appendBasicBlock('ss_ht_null_ret');
        $work = $fn->appendBasicBlock('ss_ht_work');
        $context->builder->branchIf($eitherNull, $nullRet, $work);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($work);
        $htMap = $context->structFieldMap['__hashtable__'];
        $leftCount = $context->builder->load($context->builder->structGep($left, $htMap['numElements']));
        $rightCount = $context->builder->load($context->builder->structGep($right, $htMap['numElements']));
        $gt = $context->builder->icmp(Builder::INT_UGT, $leftCount, $rightCount);
        $lt = $context->builder->icmp(Builder::INT_ULT, $leftCount, $rightCount);
        $gtRet = $fn->appendBasicBlock('ss_ht_gt');
        $ltRet = $fn->appendBasicBlock('ss_ht_lt');
        $ltCheck = $fn->appendBasicBlock('ss_ht_count_lt_check');
        $equalCounts = $fn->appendBasicBlock('ss_ht_equal_counts');
        $context->builder->branchIf($gt, $gtRet, $ltCheck);

        $context->builder->positionAtEnd($ltCheck);
        $context->builder->branchIf($lt, $ltRet, $equalCounts);

        $context->builder->positionAtEnd($gtRet);
        $context->builder->returnValue($i64->constInt(1, true));

        $context->builder->positionAtEnd($ltRet);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($equalCounts);
        // zend_hash_compare(..., ordered=0): walk left keys, lookup on the right.
        // NestedJIT stringSpaceship SIGSEGVs under thin AOT (#32536 / #21109).
        $nodePtr = $context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtr->constNull();
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $lnodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtr);
        $rscanSlot = BasicBlockHelper::entryAlloca($context, $nodePtr);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($left, $htMap['strKeys'])),
            $lnodeSlot
        );
        $leftHead = $fn->appendBasicBlock('ss_ht_left_head');
        $strDone = $fn->appendBasicBlock('ss_ht_str_done');
        $context->builder->branch($leftHead);

        $context->builder->positionAtEnd($leftHead);
        $lnode = $context->builder->load($lnodeSlot);
        $lNull = $context->builder->icmp(Builder::INT_EQ, $lnode, $nullNode);
        $leftBody = $fn->appendBasicBlock('ss_ht_left_body');
        $context->builder->branchIf($lNull, $strDone, $leftBody);

        $context->builder->positionAtEnd($leftBody);
        $lkey = $context->builder->load($context->builder->structGep($lnode, $nodeMap['key']));
        $context->builder->store(
            $context->builder->load($context->builder->structGep($right, $htMap['strKeys'])),
            $rscanSlot
        );
        $scanHead = $fn->appendBasicBlock('ss_ht_scan_head');
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanHead);
        $rnode = $context->builder->load($rscanSlot);
        $rNull = $context->builder->icmp(Builder::INT_EQ, $rnode, $nullNode);
        $scanBody = $fn->appendBasicBlock('ss_ht_scan_body');
        $missRet = $fn->appendBasicBlock('ss_ht_str_miss');
        $context->builder->branchIf($rNull, $missRet, $scanBody);

        $context->builder->positionAtEnd($missRet);
        $context->builder->returnValue($i64->constInt(1, true));

        $context->builder->positionAtEnd($scanBody);
        $rkey = $context->builder->load($context->builder->structGep($rnode, $nodeMap['key']));
        $keyMatch = \PHPCompiler\JIT\JitStringCompare::identical($context, $lkey, $rkey);
        $matched = $fn->appendBasicBlock('ss_ht_key_match');
        $scanNext = $fn->appendBasicBlock('ss_ht_scan_next');
        $context->builder->branchIf($keyMatch, $matched, $scanNext);

        $context->builder->positionAtEnd($scanNext);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($rnode, $nodeMap['next'])),
            $rscanSlot
        );
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($matched);
        // Do not call __value__spaceship here: its scalar path NestedJITs
        // CompareJitHelperScalars and SIGSEGVs under thin AOT (#32536).
        $lvalPtr = $context->builder->structGep($lnode, $nodeMap['value']);
        $rvalPtr = $context->builder->structGep($rnode, $nodeMap['value']);
        $readLong = $context->lookupFunction('__value__readLong');
        $ll = $context->builder->call(
            $readLong,
            $context->builder->pointerCast($lvalPtr, $readLong->getParam(0)->typeOf())
        );
        $rr = $context->builder->call(
            $readLong,
            $context->builder->pointerCast($rvalPtr, $readLong->getParam(0)->typeOf())
        );
        $valCmp = self::nativeI64Spaceship($context, $ll, $rr);
        $valNonZero = $context->builder->icmp(Builder::INT_NE, $valCmp, $zero64);
        $valRet = $fn->appendBasicBlock('ss_ht_val_ret');
        $leftAdvance = $fn->appendBasicBlock('ss_ht_left_next');
        $context->builder->branchIf($valNonZero, $valRet, $leftAdvance);

        $context->builder->positionAtEnd($valRet);
        $context->builder->returnValue($valCmp);

        $context->builder->positionAtEnd($leftAdvance);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($lnode, $nodeMap['next'])),
            $lnodeSlot
        );
        $context->builder->branch($leftHead);

        $context->builder->positionAtEnd($strDone);
        $context->builder->returnValue($zero64);
        $context->builder->clearInsertionPosition();
    }

    private static function slotContentToValue(Context $context, Value $content, Value $dest): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $content, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, self::blockName('ss_slot_null'));
        $checkObj = BasicBlockHelper::append($context, self::blockName('ss_slot_check_obj'));
        $objBlock = BasicBlockHelper::append($context, self::blockName('ss_slot_obj'));
        $loadBlock = BasicBlockHelper::append($context, self::blockName('ss_slot_load'));
        $done = BasicBlockHelper::append($context, self::blockName('ss_slot_done'));
        $context->builder->branchIf($isNull, $nullBlock, $checkObj);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $dest);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkObj);
        $isObject = self::slotIsObject($context, $content);
        $context->builder->branchIf($isObject, $objBlock, $loadBlock);

        $context->builder->positionAtEnd($objBlock);
        $valueMap = $context->structFieldMap['__value__'];
        $objPtr = $context->getTypeFromString('__object__*');
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_OBJECT, false),
            $context->builder->structGep($dest, $valueMap['type'])
        );
        $objSlot = $context->builder->pointerCast(
            $context->builder->structGep($dest, $valueMap['value']),
            $objPtr->pointerType(0)
        );
        $context->builder->store(
            $context->builder->pointerCast($content, $objPtr),
            $objSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($loadBlock);
        $valPtr = $context->builder->pointerCast($content, $context->getTypeFromString('__value__*'));
        $context->builder->store($context->builder->load($valPtr), $dest);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function slotIsObject(Context $context, Value $slot): Value
    {
        $refMap = $context->structFieldMap['__ref__'];
        $i32 = $context->getTypeFromString('int32');
        $voidPtr = $context->getTypeFromString('void*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $slot, $voidPtr->constNull());
        $head = $context->builder->pointerCast($slot, $context->getTypeFromString('__ref__*'));
        $typeinfo = $context->builder->load($context->builder->structGep($head, $refMap['typeinfo']));
        $masked = $context->builder->and($typeinfo, $i32->constInt(self::TYPEINFO_TYPEMASK, false));
        $isObject = $context->builder->icmp(Builder::INT_EQ, $masked, $i32->constInt(self::TYPEINFO_TYPE_OBJECT, false));

        return $context->builder->select($isNull, $context->getTypeFromString('int1')->constInt(0, false), $isObject);
    }

    private static function valueKind(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));

        return $context->builder->and(
            $context->builder->zExt($typeByte, $context->getTypeFromString('int32')),
            $context->getTypeFromString('int32')->constInt(0x7f, false)
        );
    }

    private static function valueIsNull(Context $context, Value $valuePtr): Value
    {
        return $context->builder->icmp(
            Builder::INT_EQ,
            self::valueKind($context, $valuePtr),
            $context->getTypeFromString('int32')->constInt(self::TYPE_NULL, false)
        );
    }

    private static function readBoolAsLong(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        // __value__.value is int8[8]; single-index gep keeps [8 x i8]* and load fails icmp (#21109).
        $bytePtr = $context->builder->pointerCast(
            $context->builder->structGep($valuePtr, $map['value']),
            $i8->pointerType(0)
        );
        $firstByte = $context->builder->load($bytePtr);
        $truthy = $context->builder->icmp(
            Builder::INT_NE,
            $firstByte,
            $i8->constInt(0, false)
        );

        return $context->builder->zExt($truthy, $context->getTypeFromString('int64'));
    }

    private static function readString(Context $context, Value $valuePtr): Value
    {
        return $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $strTy = $context->getStringFromType($str->typeOf());
        if ('__string__*' !== $strTy) {
            $str = $context->builder->pointerCast($str, $context->getTypeFromString('__string__*'));
        }
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $valueField = $context->builder->structGep($str, $map['value']);

        return $context->builder->pointerCast($valueField, $context->getTypeFromString('int8*'));
    }

    private static function stringSpaceship(Context $context, Value $left, Value $right): Value
    {
        $fn = SpaceshipRuntime::compareHelper(
            $context,
            'PHPCompiler\\VM\\CompareJitHelperScalars::stringSpaceship'
        );
        // NestedJIT types string params as by-value __value__; kernel holds __string__* (#25255).
        $cmp = JitNestedHelperCoerce::callHelper($context, $fn, [$left, $right]);
        $cmpI64 = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $cmp,
            $context->getTypeFromString('int64')
        );

        return $context->builder->trunc($cmpI64, $context->getTypeFromString('int32'));
    }

    private static function stringToBool(Context $context, Value $str): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $len = self::stringLen($context, $str);
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(0, false));
        $data = self::stringData($context, $str);
        $first = $context->builder->load($data);
        $isZeroChar = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(1, false)),
            $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(ord('0'), false))
        );
        $falseVal = $context->builder->or($zeroLen, $isZeroChar);

        return $context->builder->select($falseVal, $i32->constInt(0, false), $i32->constInt(1, false));
    }

    private static function spaceshipNumberString(
        Context $context,
        Value $num,
        Value $str,
        bool $numOnLeft
    ): Value {
        $fn = SpaceshipRuntime::compareHelper(
            $context,
            'PHPCompiler\\VM\\CompareJitHelperScalars::spaceshipNumberString'
        );
        $cmp = JitNestedHelperCoerce::callHelper(
            $context,
            $fn,
            [
                $num,
                $str,
                $context->getTypeFromString('int64')->constInt($numOnLeft ? 1 : 0, false),
            ]
        );

        return JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $cmp,
            $context->getTypeFromString('int64')
        );
    }

    private static function hashtableTruthyI64(Context $context, Value $valuePtr): Value
    {
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $fn = $context->lookupFunction('__hashtable__ptrIsNonEmpty');
        $ht = $context->builder->pointerCast($ht, $fn->getParam(0)->typeOf());
        $truth = $context->builder->call($fn, $ht);

        return $context->builder->zExt($truth, $context->getTypeFromString('int64'));
    }

    private static function hashtableTruthySpaceship(Context $context, Value $htValue, bool $htOnLeft): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $cmp = self::nativeI64Spaceship(
            $context,
            self::hashtableTruthyI64($context, $htValue),
            $i64->constInt(0, false)
        );

        return $htOnLeft ? $cmp : self::negateI64($context, $cmp);
    }

    private static function nativeI64Spaceship(Context $context, Value $left, Value $right): Value
    {
        $ty = $left->typeOf();
        $lt = $context->builder->icmp(Builder::INT_SLT, $left, $right);
        $gt = $context->builder->icmp(Builder::INT_SGT, $left, $right);

        return $context->builder->select(
            $gt,
            $ty->constInt(1, true),
            $context->builder->select($lt, $ty->constInt(-1, true), $ty->constInt(0, false))
        );
    }

    private static function longSpaceship(Context $context, Value $left, Value $right): Value
    {
        $fn = SpaceshipRuntime::compareHelper(
            $context,
            'PHPCompiler\\VM\\CompareJitHelperScalars::longSpaceship'
        );
        $cmp = JitNestedHelperCoerce::callHelper($context, $fn, [$left, $right]);

        return JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $cmp,
            $context->getTypeFromString('int64')
        );
    }

    private static function kindSpaceship(Context $context, Value $left, Value $right): Value
    {
        $fn = SpaceshipRuntime::compareHelper(
            $context,
            'PHPCompiler\\VM\\CompareJitHelperScalars::kindSpaceship'
        );
        $cmp = JitNestedHelperCoerce::callHelper($context, $fn, [$left, $right]);

        return JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $cmp,
            $context->getTypeFromString('int64')
        );
    }

    private static function doubleSpaceship(Context $context, Value $left, Value $right): Value
    {
        $fn = SpaceshipRuntime::compareHelper(
            $context,
            'PHPCompiler\\VM\\CompareJitHelperScalars::doubleSpaceship'
        );
        $cmp = JitNestedHelperCoerce::callHelper($context, $fn, [$left, $right]);

        return JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $cmp,
            $context->getTypeFromString('int64')
        );
    }

    private static function doubleToLong(Context $context, Value $num): Value
    {
        return $context->builder->fptosi($num, $context->getTypeFromString('int64'));
    }

    private static function i64FromI32(Context $context, Value $val): Value
    {
        return $context->builder->sext($val, $context->getTypeFromString('int64'));
    }

    private static function negateI64(Context $context, Value $val): Value
    {
        return $context->builder->sub($context->getTypeFromString('int64')->constInt(0, false), $val);
    }

    private static function objectHeaderSize(Context $context): Value
    {
        return $context->builder->ptrToInt(
            $context->builder->gep(
                $context->getTypeFromString('__object__')->pointerType(0)->constNull(),
                $context->getTypeFromString('int32')->constInt(1, false)
            ),
            $context->getTypeFromString('size_t')
        );
    }

    private static function propertySlotPtr(
        Context $context,
        Value $obj,
        Value $headerSize,
        Value $slotBytes
    ): Value {
        $i8p = $context->getTypeFromString('int8*');
        $voidpp = $context->getTypeFromString('void**');
        $base = $context->builder->pointerCast($obj, $i8p);
        $offset = $context->builder->add($headerSize, $slotBytes);

        return $context->builder->pointerCast(
            $context->builder->gep($base, $offset),
            $voidpp
        );
    }

    private static function slotPointsToString(Context $context, Value $slot): Value
    {
        $refMap = $context->structFieldMap['__ref__'];
        $i32 = $context->getTypeFromString('int32');
        $voidPtr = $context->getTypeFromString('void*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $slot, $voidPtr->constNull());
        $head = $context->builder->pointerCast($slot, $context->getTypeFromString('__ref__*'));
        $typeinfo = $context->builder->load($context->builder->structGep($head, $refMap['typeinfo']));
        $masked = $context->builder->and($typeinfo, $i32->constInt(self::TYPEINFO_TYPEMASK, false));
        $isString = $context->builder->icmp(Builder::INT_EQ, $masked, $i32->constInt(self::TYPEINFO_TYPE_STRING, false));

        return $context->builder->select($isNull, $context->getTypeFromString('int1')->constInt(0, false), $isString);
    }

    private static function objectCaseNameSlot(Context $context, Value $obj, int $slotIndex): Value
    {
        $headerSize = self::objectHeaderSize($context);
        $slotBytes = $context->getTypeFromString('size_t')->constInt(8 * $slotIndex, false);
        $slotPtr = self::propertySlotPtr($context, $obj, $headerSize, $slotBytes);
        $content = $context->builder->load($slotPtr);
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $content, $voidPtr->constNull());
        $isString = self::slotPointsToString($context, $content);
        $bad = $context->builder->or($isNull, $context->builder->not($isString));

        return $context->builder->select(
            $bad,
            $strPtr->constNull(),
            $context->builder->pointerCast($content, $strPtr)
        );
    }

    private static function blockName(string $prefix): string
    {
        return $prefix.'_'.(++self::$blockSuffix);
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
