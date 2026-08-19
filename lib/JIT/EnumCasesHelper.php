<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\EnumCasesRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Call\EnumSyntheticStatic;
use PHPCompiler\JIT\Call\Native as NativeCall;
use PHPCompiler\VM\EnumCasesJitHelper;
use PHPCompiler\VM\EnumSupport;

/**
 * JIT lowering for synthetic Enum::cases() (issue #3308, #4068, #10395).
 *
 * php-src: Zend/zend_enum.c — zend_enum_list_cases
 * SSOT: {@see EnumSupport::casesList()}, {@see EnumCasesJitHelper}
 */
final class EnumCasesHelper
{
    public static function registerCasesMethod(Context $context, ObjectBuiltin $object, int $classId): void
    {
        $classLc = strtolower(ltrim($object->classNameForId($classId), '\\'));
        $funcName = $classLc.'::cases';
        if ($context->functionIsRegistered($funcName)) {
            return;
        }
        $caseKeys = $object->enumCaseOrderForClass($classId);
        if ([] === $caseKeys) {
            return;
        }
        $caseCount = EnumCasesJitHelper::casesListLength(\count($caseKeys));
        if ($caseCount !== \count($caseKeys)) {
            throw new \LogicException('Enum::cases() JIT case count mismatch for '.$funcName);
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $fnType = $context->context->functionType($valuePtr, false);
        $fn = $context->module->addFunction($funcName, $fnType);
        $lc = strtolower($funcName);
        $context->functions[$lc] = $fn;
        // Exact 0 user args — Native alone would ignore excess SEND ops (#30864).
        $display = $object->classNameForId($classId).'::cases';
        $context->functionProxies[$lc] = new EnumSyntheticStatic(
            new NativeCall($fn, $funcName, []),
            $display,
            0
        );

        $restore = $context->builder->getInsertBlock();
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;
        $context->activeFunction = $lc;
        // ParentFunction prefers loweringLlvmFunction over the insert block (#31101).
        // finishEnumClass runs while the script function is still the owner, so the
        // cases() body would otherwise leak into {main} (#31101 / #31967).
        $context->loweringLlvmFunction = $fn instanceof \PHPLLVM\Value\Function_ ? $fn : null;
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        try {
            EnumCasesRuntime::ensureLinked($context);
            $context->builder->positionAtEnd($entry);
            $i64 = $context->getTypeFromString('int64');
            EnumCasesRuntime::callCasesListLength(
                $context,
                $i64->constInt($caseCount, false)
            );

            $caseVars = [];
            foreach ($caseKeys as $caseKey) {
                $caseVars[] = $object->jitEnumCaseFromBacking($classId, $caseKey);
            }
            $htVar = HashTableHelper::packVariables($context, $caseVars);
            // Heap __value__* — separate native function; stack alloca is use-after-return (#26855).
            $valueType = $context->getTypeFromString('__value__');
            $heapVal = $context->memory->malloc($valueType);
            $map = $context->structFieldMap['__value__'];
            $context->builder->store(
                $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
                $context->builder->structGep($heapVal, $map['type'])
            );
            $ptr = $context->builder->pointerCast(
                $heapVal,
                $context->getTypeFromString('__value__*')
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $ptr,
                $htVar->value
            );
            $context->builder->returnValue($ptr);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $restore) {
                $context->builder->positionAtEnd($restore);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }
}
