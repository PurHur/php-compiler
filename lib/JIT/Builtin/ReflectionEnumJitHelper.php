<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering helpers for ReflectionEnum introspection (#9892, php_reflection.c).
 */
final class ReflectionEnumJitHelper
{
    private const SNPRINTF_BUF = 256;

    private static int $blockSeq = 0;

    /**
     * @return array{0: Value, 1: Value} enum class name cstr and byte length
     */
    public static function reflectionEnumClassNameAsCstr(Context $context, Value $obj): array
    {
        return ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionEnum',
            ReflectionSupport::PROP_CLASS_NAME
        );
    }

    public static function emitHasCase(Context $context, Value $receiverObj, Variable $caseNameVar): Value
    {
        $tag = 'hascase_'.(++self::$blockSeq);
        $merge = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_merge');
        $resultSlot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');

        $caseStr = $context->helper->loadValue(JitNativeString::coerce($context, $caseNameVar));
        $caseData = self::stringDataPtr($context, $caseStr);
        $enumNameStr = self::enumNameStringFromReceiver($context, $receiverObj);

        self::dispatchDeclaredEnum(
            $context,
            $enumNameStr,
            $tag,
            function (Context $context, int $enumId, string $enumName) use ($caseData, $resultSlot, $merge, $i1, $tag): void {
                unset($enumName);
                $object = $context->type->object;
                $i32 = $context->getTypeFromString('int32');
                // Case-sensitive match (Zend/zend_enum.c, #25929 / #25945) — not strcasecmp.
                $strcmpFn = $context->lookupFunction('strcmp');
                $exists = $i1->constInt(0, false);
                foreach ($object->enumCaseOrderForClass($enumId) as $caseKey) {
                    $canonical = $object->enumCaseCanonicalName($enumId, $caseKey);
                    $candidate = $context->builder->load($context->constantStringFromString($canonical));
                    $candidateData = self::stringDataPtr($context, $candidate);
                    $cmp = $context->builder->call($strcmpFn, $caseData, $candidateData);
                    $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                    $exists = $context->builder->or($exists, $match);
                }
                JitValueBox::writeBool($context, $resultSlot, $exists);
                $context->builder->branch($merge);
            },
            function (Context $context) use ($resultSlot, $merge, $i1): void {
                JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));
                $context->builder->branch($merge);
            }
        );

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    public static function emitIsBacked(Context $context, Value $receiverObj): Value
    {
        $tag = 'isbacked_'.(++self::$blockSeq);
        $merge = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_merge');
        $resultSlot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $enumNameStr = self::enumNameStringFromReceiver($context, $receiverObj);

        self::dispatchDeclaredEnum(
            $context,
            $enumNameStr,
            $tag,
            function (Context $context, int $enumId, string $enumName) use ($resultSlot, $merge, $i1, $tag): void {
                unset($enumName, $tag);
                JitValueBox::writeBool(
                    $context,
                    $resultSlot,
                    $i1->constInt($context->type->object->enumHasBacking($enumId) ? 1 : 0, false)
                );
                $context->builder->branch($merge);
            },
            function (Context $context) use ($resultSlot, $merge, $i1): void {
                JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));
                $context->builder->branch($merge);
            }
        );

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * ReflectionEnum::getCases(): array — materialize case wrappers (#4121 / #27314).
     */
    public static function emitGetCases(Context $context, Value $receiverObj): Value
    {
        $tag = 'getcases_'.(++self::$blockSeq);
        $merge = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_merge');
        $resultSlot = JitValueBox::alloc($context);
        $enumNameStr = self::enumNameStringFromReceiver($context, $receiverObj);

        self::dispatchDeclaredEnum(
            $context,
            $enumNameStr,
            $tag,
            function (Context $context, int $enumId, string $enumName) use ($resultSlot, $merge): void {
                $object = $context->type->object;
                $isBacked = $object->enumHasBacking($enumId);
                $caseKeys = $object->enumCaseOrderForClass($enumId);
                $ht = HashTableHelper::alloc($context);
                $sizeT = $context->getTypeFromString('size_t');
                $need = $sizeT->constInt(\max(1, \count($caseKeys)), false);
                $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);
                foreach ($caseKeys as $i => $caseKey) {
                    $canonical = $object->enumCaseCanonicalName($enumId, $caseKey);
                    $caseObj = self::allocateReflectionEnumCase(
                        $context,
                        $enumName,
                        $canonical,
                        $isBacked
                    );
                    HashTableHelper::setAtIndex(
                        $context,
                        $ht,
                        $sizeT->constInt($i, false),
                        new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $caseObj)
                    );
                }
                $context->refcount->addref($ht);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    JitValueBox::pointer($context, $resultSlot),
                    $ht
                );
                $context->builder->branch($merge);
            },
            function (Context $context) use ($tag): void {
                TryCatchHelper::emitCatchableClassError(
                    $context,
                    'ReflectionException',
                    'ReflectionEnum refers to unknown enum in this compiler build'
                );
            }
        );

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    public static function emitGetCase(Context $context, Value $receiverObj, Variable $caseNameVar): Value
    {
        $tag = 'getcase_'.(++self::$blockSeq);
        $merge = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_merge');
        $resultSlot = JitValueBox::alloc($context);
        $caseStr = $context->helper->loadValue(JitNativeString::coerce($context, $caseNameVar));
        $caseData = self::stringDataPtr($context, $caseStr);
        $enumNameStr = self::enumNameStringFromReceiver($context, $receiverObj);

        self::dispatchDeclaredEnum(
            $context,
            $enumNameStr,
            $tag,
            function (Context $context, int $enumId, string $enumName) use ($caseData, $caseStr, $resultSlot, $merge, $tag): void {
                $object = $context->type->object;
                $i32 = $context->getTypeFromString('int32');
                // Case-sensitive match (Zend/zend_enum.c, #25929 / #25945) — not strcasecmp.
                $strcmpFn = $context->lookupFunction('strcmp');
                $caseKeys = $object->enumCaseOrderForClass($enumId);
                $lastCaseIdx = \count($caseKeys) - 1;
                $noCaseBlock = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_nocase');
                $checkCaseBlock = $context->builder->getInsertBlock();
                foreach ($caseKeys as $caseIdx => $caseKey) {
                    $context->builder->positionAtEnd($checkCaseBlock);
                    $canonical = $object->enumCaseCanonicalName($enumId, $caseKey);
                    $candidate = $context->builder->load($context->constantStringFromString($canonical));
                    $candidateData = self::stringDataPtr($context, $candidate);
                    $cmp = $context->builder->call($strcmpFn, $caseData, $candidateData);
                    $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                    $matchBlock = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_match_'.$caseIdx);
                    $nextCase = $caseIdx === $lastCaseIdx ? $noCaseBlock : BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_next_'.$caseIdx);
                    $context->builder->branchIf($match, $matchBlock, $nextCase);

                    $context->builder->positionAtEnd($matchBlock);
                    $caseObj = self::allocateReflectionEnumCase(
                        $context,
                        $enumName,
                        $canonical,
                        $object->enumHasBacking($enumId)
                    );
                    $context->builder->call(
                        $context->lookupFunction('__value__writeObject'),
                        JitValueBox::pointer($context, $resultSlot),
                        $caseObj
                    );
                    $context->builder->branch($merge);
                    $checkCaseBlock = $nextCase;
                }
                if ($checkCaseBlock !== $noCaseBlock) {
                    $context->builder->positionAtEnd($checkCaseBlock);
                    $context->builder->branch($noCaseBlock);
                }
                $context->builder->positionAtEnd($noCaseBlock);
                self::emitEnumCaseNotFound($context, $enumName, $caseStr);
            },
            function (Context $context) use ($tag): void {
                TryCatchHelper::emitCatchableClassError(
                    $context,
                    'ReflectionException',
                    'ReflectionEnum refers to unknown enum in this compiler build'
                );
            }
        );

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * ReflectionEnum::getBackingType(): ?ReflectionNamedType (#27515 / #9886).
     */
    public static function emitGetBackingType(Context $context, Value $receiverObj): Value
    {
        $tag = 'getbackingtype_'.(++self::$blockSeq);
        $merge = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_merge');
        $resultSlot = JitValueBox::alloc($context);
        $enumNameStr = self::enumNameStringFromReceiver($context, $receiverObj);

        self::dispatchDeclaredEnum(
            $context,
            $enumNameStr,
            $tag,
            function (Context $context, int $enumId, string $enumName) use ($resultSlot, $merge, $tag): void {
                unset($enumName, $tag);
                $object = $context->type->object;
                $backed = $object->enumBackedTypeFor($enumId);
                if (null === $backed || '' === $backed) {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeNull'),
                        JitValueBox::pointer($context, $resultSlot)
                    );
                    $context->builder->branch($merge);

                    return;
                }
                $named = self::allocateReflectionNamedType($context, $backed);
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    JitValueBox::pointer($context, $resultSlot),
                    $named
                );
                $context->builder->branch($merge);
            },
            function (Context $context) use ($tag): void {
                unset($tag);
                TryCatchHelper::emitCatchableClassError(
                    $context,
                    'ReflectionException',
                    'ReflectionEnum refers to unknown enum in this compiler build'
                );
            }
        );

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * Thin ReflectionNamedType for a builtin backing label (int/string/…).
     */
    public static function allocateReflectionNamedType(Context $context, string $typeName): Value
    {
        $classId = $context->type->object->lookup('ReflectionNamedType');
        $obj = $context->type->object->allocate($classId);
        ReflectionSetup::markConstructed($context, $obj);
        $len = $context->constantFromInteger(\strlen($typeName), 'size_t');
        $cstr = self::literalCstr($context, $typeName);
        foreach (
            [
                ReflectionSupport::PROP_TYPE_NAME,
                ReflectionSupport::PROP_TYPE_STRING,
            ] as $prop
        ) {
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $obj,
                'ReflectionNamedType',
                $prop,
                $cstr,
                $len
            );
        }
        $true = $context->getTypeFromString('int1')->constInt(1, false);
        $false = $context->getTypeFromString('int1')->constInt(0, false);
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_BUILTIN,
            new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $true)
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_ALLOWS_NULL,
            new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $false)
        );
        $ht = HashTableHelper::alloc($context);
        $context->refcount->addref($ht);
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_MEMBERS,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht)
        );

        return $obj;
    }

    /**
     * ReflectionEnumUnitCase::getValue() / BackedCase — materialize the enum case object (#27515).
     */
    public static function emitGetValue(Context $context, Value $receiverObj): Value
    {
        $tag = 'casegetvalue_'.(++self::$blockSeq);
        $merge = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_merge');
        $resultSlot = JitValueBox::alloc($context);

        [$caseCstr, $caseLen] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $receiverObj,
            'ReflectionEnumUnitCase',
            ReflectionSupport::PROP_CLASS_NAME
        );
        $i64 = $context->getTypeFromString('int64');
        $caseStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($caseLen, $i64),
            $caseCstr
        );
        $caseData = self::stringDataPtr($context, $caseStr);

        [$enumCstr, $enumLen] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $receiverObj,
            'ReflectionEnumUnitCase',
            ReflectionSupport::PROP_ENUM_CLASS_NAME
        );
        $enumNameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($enumLen, $i64),
            $enumCstr
        );

        self::dispatchDeclaredEnum(
            $context,
            $enumNameStr,
            $tag,
            function (Context $context, int $enumId, string $enumName) use (
                $caseData,
                $caseStr,
                $resultSlot,
                $merge,
                $tag
            ): void {
                $object = $context->type->object;
                $i32 = $context->getTypeFromString('int32');
                $strcmpFn = $context->lookupFunction('strcmp');
                $caseKeys = $object->enumCaseOrderForClass($enumId);
                $lastCaseIdx = \count($caseKeys) - 1;
                $noCaseBlock = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_nocase');
                $checkCaseBlock = $context->builder->getInsertBlock();
                foreach ($caseKeys as $caseIdx => $caseKey) {
                    $context->builder->positionAtEnd($checkCaseBlock);
                    $canonical = $object->enumCaseCanonicalName($enumId, $caseKey);
                    $candidate = $context->builder->load($context->constantStringFromString($canonical));
                    $candidateData = self::stringDataPtr($context, $candidate);
                    $cmp = $context->builder->call($strcmpFn, $caseData, $candidateData);
                    $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                    $matchBlock = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_match_'.$caseIdx);
                    $nextCase = $caseIdx === $lastCaseIdx
                        ? $noCaseBlock
                        : BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_next_'.$caseIdx);
                    $context->builder->branchIf($match, $matchBlock, $nextCase);

                    $context->builder->positionAtEnd($matchBlock);
                    $globalName = $object->ensureEnumCaseSingletonGlobal($enumId, $caseKey);
                    $global = $context->module->getNamedGlobal($globalName);
                    if (null === $global) {
                        throw new \LogicException("Missing enum case singleton global: {$globalName}");
                    }
                    $caseObj = $context->builder->load($global);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeObject'),
                        JitValueBox::pointer($context, $resultSlot),
                        $caseObj
                    );
                    $context->builder->branch($merge);
                    $checkCaseBlock = $nextCase;
                }
                if ($checkCaseBlock !== $noCaseBlock) {
                    $context->builder->positionAtEnd($checkCaseBlock);
                    $context->builder->branch($noCaseBlock);
                }
                $context->builder->positionAtEnd($noCaseBlock);
                self::emitEnumCaseNotFound($context, $enumName, $caseStr);
            },
            function (Context $context) use ($tag): void {
                unset($tag);
                TryCatchHelper::emitCatchableClassError(
                    $context,
                    'ReflectionException',
                    'ReflectionEnumUnitCase refers to unknown enum in this compiler build'
                );
            }
        );

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    public static function allocateReflectionEnumCase(
        Context $context,
        string $enumName,
        string $canonicalCaseName,
        bool $isBacked
    ): Value {
        $className = $isBacked ? 'ReflectionEnumBackedCase' : 'ReflectionEnumUnitCase';
        $classId = $context->type->object->lookup($className);
        $obj = $context->type->object->allocate($classId);
        ReflectionSetup::markConstructed($context, $obj);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            $className,
            ReflectionSupport::PROP_CLASS_NAME,
            self::literalCstr($context, $canonicalCaseName),
            $context->constantFromInteger(\strlen($canonicalCaseName), 'size_t')
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            $className,
            ReflectionSupport::PROP_ENUM_CLASS_NAME,
            self::literalCstr($context, $enumName),
            $context->constantFromInteger(\strlen($enumName), 'size_t')
        );

        return $obj;
    }

    private static function enumNameStringFromReceiver(Context $context, Value $receiverObj): Value
    {
        [$enumClassCstr, $enumClassLen] = self::reflectionEnumClassNameAsCstr($context, $receiverObj);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($enumClassLen, $context->getTypeFromString('int64')),
            $enumClassCstr
        );
    }

    /**
     * @param callable(Context, int, string): void $onEnum
     * @param callable(Context): void $onMiss
     */
    private static function dispatchDeclaredEnum(
        Context $context,
        Value $enumNameStr,
        string $tag,
        callable $onEnum,
        callable $onMiss
    ): void {
        $object = $context->type->object;
        $enumIds = $object->registeredEnumClassIds();
        if ([] === $enumIds) {
            $onMiss($context);

            return;
        }

        StringCaseCompare::ensureStrcasecmpLinked($context);
        $nameData = self::stringDataPtr($context, $enumNameStr);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $i32 = $context->getTypeFromString('int32');
        $miss = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_miss');

        $checkBlock = $context->builder->getInsertBlock();
        $lastEnumIdx = \count($enumIds) - 1;
        foreach ($enumIds as $enumIdx => $enumId) {
            $enumName = $object->classNameForId($enumId);
            $enumLc = strtolower(ltrim($enumName, '\\'));
            $context->builder->positionAtEnd($checkBlock);
            $candidate = $context->builder->load($context->constantStringFromString($enumLc));
            $candidateData = self::stringDataPtr($context, $candidate);
            $cmp = $context->builder->call($strcasecmpFn, $nameData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $matchBlock = BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_match_'.$enumId);
            $nextBlock = $enumIdx === $lastEnumIdx ? $miss : BasicBlockHelper::append($context, 'refl_enum_'.$tag.'_try_'.($enumIdx + 1));
            $context->builder->branchIf($match, $matchBlock, $nextBlock);

            $context->builder->positionAtEnd($matchBlock);
            $onEnum($context, $enumId, $enumName);
            $checkBlock = $nextBlock;
        }
        if ($checkBlock !== $miss) {
            $context->builder->positionAtEnd($checkBlock);
            $context->builder->branch($miss);
        }

        $context->builder->positionAtEnd($miss);
        $onMiss($context);
    }

    private static function emitEnumCaseNotFound(Context $context, string $enumName, Value $caseStrPtr): void
    {
        [$written, $len] = self::snprintfToStack(
            $context,
            'Case %s::%s does not exist',
            [self::literalCstr($context, $enumName), self::stringDataPtr($context, $caseStrPtr)]
        );
        $msgStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $context->getTypeFromString('int64')),
            $written
        );
        $object = $context->type->object;
        $classId = $object->lookup('ReflectionException');
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $msgStr
        );
        $object->storeInstanceProperty($obj, 'ReflectionException', 'message', $msgVar);
        $handler = TryCatchHelper::resolveThrowHandler($context);
        if (null === $handler || null === $handler->dispatchBb) {
            ErrorRaise::emitRaise($context, 'Case '.$enumName.'::? does not exist');

            return;
        }
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $context->builder->branch($handler->dispatchBb);
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        return $context->builder->structGep($strPtr, $context->structFieldIndex($strPtr, 'value'));
    }

    private static function literalCstr(Context $context, string $literal): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($literal),
            $context->getTypeFromString('char*')
        );
    }

    /**
     * @param list<Value> $extraArgs
     *
     * @return array{0: Value, 1: Value}
     */
    private static function snprintfToStack(Context $context, string $fmt, array $extraArgs): array
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $buf = $context->builder->alloca($i8, self::SNPRINTF_BUF, 'refl_enum_snprintf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmtCstr = $context->builder->pointerCast($context->constantFromString($fmt), $charPtr);
        $args = [$bufChar, $sizeT->constInt(self::SNPRINTF_BUF, false), $fmtCstr, ...$extraArgs];
        $written = $context->builder->call($context->lookupFunction('snprintf'), ...$args);

        return [$context->builder->pointerCast($buf, $charPtr), $written];
    }
}
