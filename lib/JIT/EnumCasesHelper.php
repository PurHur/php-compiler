<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Call\Native as NativeCall;

/**
 * JIT lowering for synthetic Enum::cases() (issue #3308, #4068).
 *
 * php-src: Zend/zend_enum.c — zend_enum_list_cases
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

        $void = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fnType = $context->context->functionType($valuePtr, false);
        $fn = $context->module->addFunction($funcName, $fnType);
        $lc = strtolower($funcName);
        $context->functions[$lc] = $fn;
        $context->functionProxies[$lc] = new NativeCall($fn, $funcName, []);

        $restore = $context->builder->getInsertBlock();
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $ht = HashTableHelper::alloc($context);
        foreach ($caseKeys as $index => $caseKey) {
            $caseObj = $object->jitEnumCaseFromBacking($classId, $caseKey);
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->getTypeFromString('int64')->constInt($index, false),
                $caseObj
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->returnValue($ptr);
        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
