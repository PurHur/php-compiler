<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\simplexml\JitSimpleXmlUserScript;

/**
 * Thin-AOT SimpleXMLElement foreach via host-tree child snapshot → hashtable (#27535).
 *
 * NestedJIT of VmSimpleXml iterator methods is deferred under user-script AOT;
 * ExternalMethod stubs for rewind/valid made foreach empty or segfault after children().
 * When a host tree is known, pack children (php-src sxe iterator) like {@see DatePeriodForeachSnapshot}.
 *
 * Keys are element/attribute names (Zend sxe_iterator_get_current_key), not packed indices (#34543).
 */
final class SimpleXmlForeachSnapshot
{
    public static function canLower(Variable $array): bool
    {
        return null !== JitSimpleXmlUserScript::hostTreeForForeach($array);
    }

    public static function compileReset(Context $context, Variable $array, Variable $slotKey): void
    {
        $tree = JitSimpleXmlUserScript::hostTreeForForeach($array);
        if (null === $tree) {
            throw new \LogicException('SimpleXML foreach snapshot missing host tree (#27535)');
        }

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $n = 0;
        try {
            foreach ($tree as $key => $child) {
                if (!($child instanceof \SimpleXMLElement)) {
                    continue;
                }
                $classId = $context->type->object->lookup('SimpleXMLElement');
                $obj = $context->type->object->allocate($classId);
                $context->type->object->markObjectConstructed($obj);
                $receiver = new Variable(
                    $context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $obj
                );
                // Bind host child so nested getName/cast/foreach can fold (#27535).
                JitSimpleXmlUserScript::bindHostTreeForSnapshot($context, $receiver, $child);
                $keyStr = $context->builder->load(
                    $context->constantStringFromString((string) $key)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $receiver);
                ++$n;
            }
        } catch (\Throwable $e) {
            throw new \LogicException(
                'SimpleXML foreach snapshot failed to walk host tree (#27535): '.$e->getMessage(),
                0,
                $e
            );
        }

        $htVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht);
        $key = $context->foreachSlotMapKey($slotKey);
        // Reuse DatePeriod snapshot HT table — same packed-HT foreach walk (#26772 / #27535).
        $context->foreachDatePeriodSnapshotHts[$key] = $htVar;
        // Mark so FE_FETCH returns TYPE_OBJECT — TYPE_VALUE skips tryFoldStringCast and SIGSEGVs (#34543).
        $context->foreachSimpleXmlSnapshotSlots[$key] = true;

        $sizeT = $context->getTypeFromString('size_t');
        if (!isset($context->foreachIndexSlots[$key])) {
            $context->foreachIndexSlots[$key] = BasicBlockHelper::entryAlloca($context, $sizeT);
        }
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, $context->foreachIndexSlots[$key]);
        unset($n);
    }
}
