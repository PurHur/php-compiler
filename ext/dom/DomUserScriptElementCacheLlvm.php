<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT: last loadHTML element cache keyed by element id (#17954).
 *
 * Bridges pure-LLVM loadHTML until PROP_ELEMENT_ID_MAP persistence matches across
 * method-call receiver temps after DomDocumentLoadHTML scope restore.
 */
final class DomUserScriptElementCacheLlvm
{
    private const GLOBAL_OK = '__phpc_dom_us_ok';

    private const GLOBAL_ID = '__phpc_dom_us_id';

    private const GLOBAL_ELEM = '__phpc_dom_us_elem';

    public static function store(
        Context $context,
        Value $document,
        Value $idStr,
        Value $element
    ): void {
        self::ensureGlobals($context);
        $i1 = $context->getTypeFromString('int1');

        $context->builder->store($i1->constInt(1, false), $context->module->getNamedGlobal(self::GLOBAL_OK));

        $ownedId = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $idStr
        );
        $context->builder->store($ownedId, $context->module->getNamedGlobal(self::GLOBAL_ID));
        $context->builder->store($element, $context->module->getNamedGlobal(self::GLOBAL_ELEM));
    }

    /** @return Value {@see __object__*} element or null */
    public static function lookupObject(Context $context, Value $idStr): Value
    {
        self::ensureGlobals($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $i32 = $context->getTypeFromString('int32');
        $nullObj = $objPtr->constNull();
        $i1 = $context->getTypeFromString('int1');

        $storedOk = $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_OK));
        $hasStore = $context->builder->icmp(Builder::INT_EQ, $storedOk, $i1->constInt(1, false));

        $cachedId = $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_ID));
        $hasCachedId = $context->builder->icmp(
            Builder::INT_NE,
            $cachedId,
            $cachedId->typeOf()->constNull()
        );

        $missBlock = BasicBlockHelper::append($context, 'dom_us_cache_miss');
        $cmpBlock = BasicBlockHelper::append($context, 'dom_us_cache_cmp');
        $doneBlock = BasicBlockHelper::append($context, 'dom_us_cache_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $objPtr);
        $precheck = $context->builder->and($hasStore, $hasCachedId);
        $context->builder->branchIf($precheck, $cmpBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $context->builder->store($nullObj, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($cmpBlock);
        $cmp = JitStringCompare::strcmp($context, $idStr, $cachedId);
        $i64 = $context->getTypeFromString('int64');
        $idMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $cachedElem = $context->builder->load($context->module->getNamedGlobal(self::GLOBAL_ELEM));
        $found = $context->builder->select($idMatch, $cachedElem, $nullObj);
        $context->builder->store($found, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function ensureGlobals(Context $context): void
    {
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        $objPtr = $context->getTypeFromString('__object__*');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_OK)) {
            $g = $context->module->addGlobal($i1, self::GLOBAL_OK);
            $g->setInitializer($i1->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_ID)) {
            $g = $context->module->addGlobal($strPtr, self::GLOBAL_ID);
            $g->setInitializer($strPtr->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_ELEM)) {
            $g = $context->module->addGlobal($objPtr, self::GLOBAL_ELEM);
            $g->setInitializer($objPtr->constNull());
        }
    }
}
