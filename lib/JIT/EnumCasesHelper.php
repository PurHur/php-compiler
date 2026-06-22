<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\EnumCasesRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
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
        $context->functionProxies[$lc] = new NativeCall($fn, $funcName, []);

        $restore = $context->builder->getInsertBlock();
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        EnumCasesRuntime::ensureLinked($context);
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
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $htVar->value
        );
        $context->builder->returnValue($ptr);
        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
