<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionMethod visibility + arity queries (#34216).
 */
final class ReflectionMethodQueryLookupRuntime
{
    private static int $inlineSeq = 0;

    public static function lookupFlagsInlineFromStrings(
        Context $context,
        Value $classStr,
        Value $methodStr,
        array $visibility
    ): Value {
        StringNCompare::ensureStrncmpLinked($context);
        StringCaseCompare::ensureStrncasecmpLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $entries = self::flattenClassMethodEntries($visibility);
        $flags = $zero;
        $strPtrTy = $context->getTypeFromString('__string__*');
        $lenPtrTy = $context->getTypeFromString('int64*');
        $methodRaw = $context->builder->pointerCast($methodStr, $context->getTypeFromString('int8*'));
        $methodLenPtr = $context->builder->pointerCast(
            $context->builder->gep($methodRaw, $context->constantFromInteger(8, 'size_t')),
            $lenPtrTy
        );
        $methodLenLive = $context->builder->load($methodLenPtr);
        foreach ($entries as [$classLc, $methodLc, $entryFlags]) {
            unset($classLc);
            $methodLc = strtolower($methodLc);
            $expectedLen = $i64->constInt(\strlen($methodLc), false);
            $lenOk = $context->builder->icmp(Builder::INT_EQ, $methodLenLive, $expectedLen);
            $methodLit = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $expectedLen,
                $context->builder->pointerCast($context->constantFromString($methodLc), $i8p)
            );
            $methodCmp = $context->builder->call(
                $context->lookupFunction(StringNCompare::ABI_STRNCMP),
                $methodStr,
                $methodLit,
                $expectedLen
            );
            $methodOk = $context->builder->and(
                $lenOk,
                $context->builder->icmp(Builder::INT_EQ, $methodCmp, $zeroI64)
            );
            $flags = $context->builder->select(
                $methodOk,
                $i32->constInt((int) $entryFlags, false),
                $flags
            );
        }

        return $flags;
    }

    public static function lookupParamCountInlineFromStrings(
        Context $context,
        Value $classStr,
        Value $methodStr,
        array $paramMap
    ): Value {
        StringNCompare::ensureStrncmpLinked($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $sizeT->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $entries = self::flattenClassMethodEntries($paramMap);
        $count = $zero;
        $methodRaw = $context->builder->pointerCast($methodStr, $context->getTypeFromString('int8*'));
        $methodLenPtr = $context->builder->pointerCast(
            $context->builder->gep($methodRaw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $methodLenLive = $context->builder->load($methodLenPtr);
        foreach ($entries as [$classLc, $methodLc, $entryCount]) {
            unset($classLc);
            $methodLc = strtolower($methodLc);
            $expectedLen = $i64->constInt(\strlen($methodLc), false);
            $lenOk = $context->builder->icmp(Builder::INT_EQ, $methodLenLive, $expectedLen);
            $methodLit = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $expectedLen,
                $context->builder->pointerCast($context->constantFromString($methodLc), $i8p)
            );
            $methodCmp = $context->builder->call(
                $context->lookupFunction(StringNCompare::ABI_STRNCMP),
                $methodStr,
                $methodLit,
                $expectedLen
            );
            $methodOk = $context->builder->and(
                $lenOk,
                $context->builder->icmp(Builder::INT_EQ, $methodCmp, $zeroI64)
            );
            $count = $context->builder->select(
                $methodOk,
                $sizeT->constInt((int) $entryCount, false),
                $count
            );
        }

        return $count;
    }

    public static function lookupFlagsInline(
        Context $context,
        Value $classCstr,
        Value $methodCstr,
        array $visibility,
        ?Value $classLen = null,
        ?Value $methodLen = null
    ): Value {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        if (null !== $classLen && null !== $methodLen) {
            StringCaseCompare::ensureStrncasecmpLinked($context);
        }
        $tag = 'rmq'.(string) (++self::$inlineSeq);

        return self::lookupFlagsFromCstrPair(
            $context,
            null,
            $classCstr,
            $methodCstr,
            $visibility,
            $tag,
            $classLen,
            $methodLen
        );
    }

    public static function lookupParamCountInline(
        Context $context,
        Value $classCstr,
        Value $methodCstr,
        array $paramMap,
        ?Value $classLen = null,
        ?Value $methodLen = null
    ): Value {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        if (null !== $classLen && null !== $methodLen) {
            StringCaseCompare::ensureStrncasecmpLinked($context);
        }
        $tag = 'rmqpc'.(string) (++self::$inlineSeq);

        return self::lookupCountFromCstrPair(
            $context,
            null,
            $classCstr,
            $methodCstr,
            $paramMap,
            $tag,
            $classLen,
            $methodLen
        );
    }

    public static function implement(
        Context $context,
        string $visibilityJson,
        string $totalParamJson,
        string $requiredParamJson
    ): void {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $visibility = self::decodeIntMap($visibilityJson);
        $totalParams = self::decodeIntMap($totalParamJson);
        $requiredParams = self::decodeIntMap($requiredParamJson);

        self::implementFlagsBridge($context, $visibility);
        self::implementFlagsMethodStringBridge($context, $visibility);
        self::implementParamCountBridge($context, '__compiler_refl_method_param_count', $totalParams);
        self::implementParamCountMethodStringBridge($context, '__compiler_refl_method_param_count_method_str', $totalParams);
        self::implementParamCountBridge($context, '__compiler_refl_method_required_param_count', $requiredParams);
        self::implementParamCountMethodStringBridge(
            $context,
            '__compiler_refl_method_required_param_count_method_str',
            $requiredParams
        );
        self::implementFlagsObjectBridge($context, $visibility);
        self::implementParamCountObjectBridge($context, '__compiler_refl_method_param_count_obj', $totalParams);
        self::implementParamCountObjectBridge($context, '__compiler_refl_method_required_param_count_obj', $requiredParams);
        $context->builder->clearInsertionPosition();
    }

    private static function cstrFromStringObject(Context $context, Value $strPtr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $data->typeOf()->constNull());

        return $context->builder->select($isNull, $empty, $context->builder->pointerCast($data, $i8p));
    }

    /** @param array<string, array<string, int>> $visibility */
    private static function implementFlagsMethodStringBridge(Context $context, array $visibility): void
    {
        $abiName = '__compiler_refl_method_flags_method_str';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i32, false, $i8p, $strPtrTy);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_method_flags_method_str_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = self::cstrFromStringObject($context, $fn->getParam(1));
        $zero = $i32->constInt(0, false);

        $entries = self::flattenClassMethodEntries($visibility);
        if ([] === $entries) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_method_flags_method_str_merge');
        $resultSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i32);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($entries as [$classLc, $methodLc, $flags]) {
            $check = BasicBlockHelper::append($context, 'refl_method_flags_method_str_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_method_flags_method_str_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $both = self::classMethodMatchI1(
                $context,
                $classCstr,
                $methodCstr,
                $classLc,
                $methodLc,
                $i8p,
                $i32
            );
            $fallthrough = BasicBlockHelper::append($context, 'refl_method_flags_method_str_next_'.$seq);
            $context->builder->branchIf($both, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($i32->constInt((int) $flags, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, int>> $paramMap */
    private static function implementParamCountMethodStringBridge(
        Context $context,
        string $abiName,
        array $paramMap
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($sizeT, false, $i8p, $strPtrTy);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock($abiName.'_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = self::cstrFromStringObject($context, $fn->getParam(1));
        $zero = $sizeT->constInt(0, false);

        $entries = self::flattenClassMethodEntries($paramMap);
        if ([] === $entries) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, $abiName.'_merge');
        $resultSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($entries as [$classLc, $methodLc, $count]) {
            $check = BasicBlockHelper::append($context, $abiName.'_check_'.$seq);
            $match = BasicBlockHelper::append($context, $abiName.'_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $both = self::classMethodMatchI1(
                $context,
                $classCstr,
                $methodCstr,
                $classLc,
                $methodLc,
                $i8p,
                $context->getTypeFromString('int32')
            );
            $fallthrough = BasicBlockHelper::append($context, $abiName.'_next_'.$seq);
            $context->builder->branchIf($both, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($sizeT->constInt((int) $count, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, int>> $visibility */
    private static function implementFlagsObjectBridge(Context $context, array $visibility): void
    {
        $abiName = '__compiler_refl_method_flags_obj';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $objPtrTy);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_method_flags_obj_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        [$classCstr] = self::emitReflectionMethodPropertyCstr(
            $context,
            $fn,
            $obj,
            ReflectionSupport::PROP_REFLECTION_METHOD_CLASS
        );
        [$methodCstr] = self::emitReflectionMethodPropertyCstr(
            $context,
            $fn,
            $obj,
            ReflectionSupport::PROP_REFLECTION_METHOD_FUNC
        );
        $flags = self::lookupFlagsFromCstrPair($context, $fn, $classCstr, $methodCstr, $visibility);
        $context->builder->returnValue($flags);
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, int>> $paramMap */
    private static function implementParamCountObjectBridge(
        Context $context,
        string $abiName,
        array $paramMap
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($sizeT, false, $objPtrTy);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock($abiName.'_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        [$classCstr] = self::emitReflectionMethodPropertyCstr(
            $context,
            $fn,
            $obj,
            ReflectionSupport::PROP_REFLECTION_METHOD_CLASS
        );
        [$methodCstr] = self::emitReflectionMethodPropertyCstr(
            $context,
            $fn,
            $obj,
            ReflectionSupport::PROP_REFLECTION_METHOD_FUNC
        );
        $count = self::lookupCountFromCstrPair($context, $fn, $classCstr, $methodCstr, $paramMap);
        $context->builder->returnValue($count);
        $context->registerFunction($abiName, $fn);
    }

    /**
     * @return array{0: \PHPLLVM\Value}
     */
    private static function emitReflectionMethodPropertyCstr(
        Context $context,
        $fn,
        $obj,
        string $propName
    ): array {
        [$cstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionMethod',
            $propName
        );

        return [$cstr];
    }

    /** @param array<string, array<string, int>> $visibility */
    private static function lookupFlagsFromCstrPair(
        Context $context,
        $fn,
        $classCstr,
        $methodCstr,
        array $visibility,
        string $tag = 'refl_method_flags_obj',
        ?Value $classLen = null,
        ?Value $methodLen = null
    ) {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $entries = self::flattenClassMethodEntries($visibility);
        if ([] === $entries) {
            return $zero;
        }

        // Call-site lookup must stay in the current block: branching to a chain of
        // compare blocks leaves methodCstr pointing at the first declared method (#34216).
        if (null === $fn) {
            $flags = $zero;
            foreach ($entries as [$classLc, $methodLc, $entryFlags]) {
                $both = self::classMethodMatchI1(
                    $context,
                    $classCstr,
                    $methodCstr,
                    $classLc,
                    $methodLc,
                    $i8p,
                    $i32,
                    $classLen,
                    $methodLen
                );
                $flags = $context->builder->select(
                    $both,
                    $i32->constInt((int) $entryFlags, false),
                    $flags
                );
            }

            return $flags;
        }

        $entry = $context->builder->getInsertBlock();
        $merge = BasicBlockHelper::append($context, $tag.'_merge');
        $resultSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i32);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($entries as [$classLc, $methodLc, $flags]) {
            $check = BasicBlockHelper::append($context, $tag.'_check_'.$seq);
            $match = BasicBlockHelper::append($context, $tag.'_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $both = self::classMethodMatchI1(
                $context,
                $classCstr,
                $methodCstr,
                $classLc,
                $methodLc,
                $i8p,
                $i32
            );
            $fallthrough = BasicBlockHelper::append($context, $tag.'_next_'.$seq);
            $context->builder->branchIf($both, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($i32->constInt((int) $flags, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /** @param array<string, array<string, int>> $paramMap */
    private static function lookupCountFromCstrPair(
        Context $context,
        $fn,
        $classCstr,
        $methodCstr,
        array $paramMap,
        string $tag = 'refl_method_param_obj',
        ?Value $classLen = null,
        ?Value $methodLen = null
    ) {
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $zero = $sizeT->constInt(0, false);
        $entries = self::flattenClassMethodEntries($paramMap);
        if ([] === $entries) {
            return $zero;
        }

        if (null === $fn) {
            $count = $zero;
            foreach ($entries as [$classLc, $methodLc, $entryCount]) {
                $both = self::classMethodMatchI1(
                    $context,
                    $classCstr,
                    $methodCstr,
                    $classLc,
                    $methodLc,
                    $i8p,
                    $i32,
                    $classLen,
                    $methodLen
                );
                $count = $context->builder->select(
                    $both,
                    $sizeT->constInt((int) $entryCount, false),
                    $count
                );
            }

            return $count;
        }

        $entry = $context->builder->getInsertBlock();
        $merge = BasicBlockHelper::append($context, $tag.'_merge');
        $resultSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($entries as [$classLc, $methodLc, $count]) {
            $check = BasicBlockHelper::append($context, $tag.'_check_'.$seq);
            $match = BasicBlockHelper::append($context, $tag.'_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $both = self::classMethodMatchI1(
                $context,
                $classCstr,
                $methodCstr,
                $classLc,
                $methodLc,
                $i8p,
                $i32
            );
            $fallthrough = BasicBlockHelper::append($context, $tag.'_next_'.$seq);
            $context->builder->branchIf($both, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($sizeT->constInt((int) $count, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /** @param array<string, array<string, int>> $visibility */
    private static function implementFlagsBridge(Context $context, array $visibility): void
    {
        $abiName = '__compiler_refl_method_flags';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_method_flags_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $zero = $i32->constInt(0, false);

        $entries = self::flattenClassMethodEntries($visibility);
        if ([] === $entries) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_method_flags_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($entries as [$classLc, $methodLc, $flags]) {
            $check = BasicBlockHelper::append($context, 'refl_method_flags_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_method_flags_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $both = self::classMethodMatchI1(
                $context,
                $classCstr,
                $methodCstr,
                $classLc,
                $methodLc,
                $i8p,
                $i32
            );
            $fallthrough = BasicBlockHelper::append($context, 'refl_method_flags_next_'.$seq);
            $context->builder->branchIf($both, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($i32->constInt((int) $flags, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, int>> $paramMap */
    private static function implementParamCountBridge(
        Context $context,
        string $abiName,
        array $paramMap
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_method_param_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $zero = $sizeT->constInt(0, false);

        $entries = self::flattenClassMethodEntries($paramMap);
        if ([] === $entries) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, $abiName.'_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($entries as [$classLc, $methodLc, $count]) {
            $check = BasicBlockHelper::append($context, $abiName.'_check_'.$seq);
            $match = BasicBlockHelper::append($context, $abiName.'_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $both = self::classMethodMatchI1(
                $context,
                $classCstr,
                $methodCstr,
                $classLc,
                $methodLc,
                $i8p,
                $i32
            );
            $fallthrough = BasicBlockHelper::append($context, $abiName.'_next_'.$seq);
            $context->builder->branchIf($both, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($sizeT->constInt((int) $count, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    private static function classMethodMatchI1(
        Context $context,
        $classCstr,
        $methodCstr,
        string $classLc,
        string $methodLc,
        $i8p,
        $i32,
        ?Value $classLen = null,
        ?Value $methodLen = null
    ) {
        $sizeT = $context->getTypeFromString('size_t');
        $classDisplay = ltrim($classLc, '\\');
        $classExpected = $context->builder->pointerCast(
            $context->constantFromString($classDisplay),
            $i8p
        );
        $methodExpected = $context->builder->pointerCast(
            $context->constantFromString(strtolower($methodLc)),
            $i8p
        );

        if (null !== $classLen && null !== $methodLen) {
            $classExpectedLen = $sizeT->constInt(\strlen($classDisplay), false);
            $methodExpectedLen = $sizeT->constInt(\strlen($methodLc), false);
            $classLenOk = $context->builder->icmp(Builder::INT_EQ, $classLen, $classExpectedLen);
            $methodLenOk = $context->builder->icmp(Builder::INT_EQ, $methodLen, $methodExpectedLen);
            $classEq = $context->builder->call(
                $context->lookupFunction(StringCaseCompare::ABI_STRNCASECMP),
                $classCstr,
                $classExpected,
                $classExpectedLen
            );
            $methodEq = $context->builder->call(
                $context->lookupFunction(StringCaseCompare::ABI_STRNCASECMP),
                $methodCstr,
                $methodExpected,
                $methodExpectedLen
            );
            $classOk = $context->builder->and(
                $classLenOk,
                $context->builder->icmp(Builder::INT_EQ, $classEq, $i32->constInt(0, false))
            );
            $methodOk = $context->builder->and(
                $methodLenOk,
                $context->builder->icmp(Builder::INT_EQ, $methodEq, $i32->constInt(0, false))
            );

            return $context->builder->and($classOk, $methodOk);
        }

        $classEq = $context->builder->call(
            $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
            $classCstr,
            $classExpected
        );
        $classOk = $context->builder->icmp(Builder::INT_EQ, $classEq, $i32->constInt(0, false));

        $methodEq = $context->builder->call(
            $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
            $methodCstr,
            $methodExpected
        );
        $methodOk = $context->builder->icmp(Builder::INT_EQ, $methodEq, $i32->constInt(0, false));

        return $context->builder->and($classOk, $methodOk);
    }

    /**
     * @param array<string, array<string, int>> $map
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function flattenClassMethodEntries(array $map): array
    {
        $out = [];
        foreach ($map as $classLc => $methods) {
            if (!\is_array($methods)) {
                continue;
            }
            foreach ($methods as $methodLc => $value) {
                if (\is_string($methodLc) && '' !== $methodLc && is_numeric($value)) {
                    $out[] = [(string) $classLc, $methodLc, (int) $value];
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, int>> */
    private static function decodeIntMap(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $class => $methods) {
            if (!\is_string($class) || '' === $class || !\is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $value) {
                if (\is_string($method) && '' !== $method && is_numeric($value)) {
                    $out[strtolower($class)][strtolower($method)] = (int) $value;
                }
            }
        }

        return $out;
    }
}
