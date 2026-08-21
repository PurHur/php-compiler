<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * AOT markup for DocumentType stand-ins (#33584).
 *
 * php-src: ext/dom/document.c / libxml xmlNodeDump(XML_DOCUMENT_TYPE_NODE) —
 * {@code <!DOCTYPE name>}, {@code PUBLIC "p" "s"}, or {@code SYSTEM "s"}.
 * Peer VM {@see VmDom::serializeDoctype}.
 */
final class JitDomDocumentTypeMarkup
{
    /**
     * True when {@code $node} is a createDocumentType stand-in ({@see JitDomCreateDocumentType::TAG_KIND}).
     */
    public static function isDocumentTypeStandIn(Context $context, Value $node): Value
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'tagName')) {
            $objectType->defineProperty($elementClassId, 'tagName', JITVariable::TYPE_STRING);
        }
        $tagVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $tagStr = $context->helper->loadValue($tagVar);
        $kindLit = $context->builder->load(
            $context->constantStringFromString(JitDomCreateDocumentType::TAG_KIND)
        );
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $tagStr, $kindLit),
            $i64->constInt(0, false)
        );
    }

    /**
     * Build {@code <!DOCTYPE …>} __string__* from stand-in name/publicId/systemId slots.
     */
    public static function serializeStandIn(Context $context, Value $node): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_dt_markup');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach (['name', 'publicId', 'systemId', 'nodeName'] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_STRING);
            }
        }

        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'name',
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $publicVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'publicId',
            $elementClassId
        );
        $publicStr = $context->helper->loadValue($publicVar);
        $systemVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'systemId',
            $elementClassId
        );
        $systemStr = $context->helper->loadValue($systemVar);

        $empty = $context->builder->load($context->constantStringFromString(''));
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $publicEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $publicStr, $empty),
            $zero
        );
        $systemEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $systemStr, $empty),
            $zero
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $bbPublic = BasicBlockHelper::append($context, 'dom_dt_public');
        $bbCheckSystem = BasicBlockHelper::append($context, 'dom_dt_check_system');
        $bbSystem = BasicBlockHelper::append($context, 'dom_dt_system');
        $bbBare = BasicBlockHelper::append($context, 'dom_dt_bare');
        $bbDone = BasicBlockHelper::append($context, 'dom_dt_done');
        $context->builder->branchIf($publicEmpty, $bbCheckSystem, $bbPublic);

        $context->builder->positionAtEnd($bbPublic);
        // <!DOCTYPE name PUBLIC "publicId" "systemId">
        $openPub = $context->builder->load($context->constantStringFromString('<!DOCTYPE '));
        $pubMid = $context->builder->load($context->constantStringFromString(' PUBLIC "'));
        $pubSys = $context->builder->load($context->constantStringFromString('" "'));
        $closeQ = $context->builder->load($context->constantStringFromString('">'));
        $pubXml = self::concatMany($context, [
            $openPub,
            $nameStr,
            $pubMid,
            $publicStr,
            $pubSys,
            $systemStr,
            $closeQ,
        ]);
        $context->builder->store($pubXml, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckSystem);
        $context->builder->branchIf($systemEmpty, $bbBare, $bbSystem);

        $context->builder->positionAtEnd($bbSystem);
        $openSys = $context->builder->load($context->constantStringFromString('<!DOCTYPE '));
        $sysMid = $context->builder->load($context->constantStringFromString(' SYSTEM "'));
        $closeSys = $context->builder->load($context->constantStringFromString('">'));
        $sysXml = self::concatMany($context, [$openSys, $nameStr, $sysMid, $systemStr, $closeSys]);
        $context->builder->store($sysXml, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBare);
        $openBare = $context->builder->load($context->constantStringFromString('<!DOCTYPE '));
        $closeBare = $context->builder->load($context->constantStringFromString('>'));
        $bareXml = self::concatMany($context, [$openBare, $nameStr, $closeBare]);
        $context->builder->store($bareXml, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    /**
     * Store DocumentType stand-in on {@code $document->doctype} (VALUE slot, #28940).
     */
    public static function storeOnDocument(Context $context, Value $document, Value $doctype): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_dt_store_doc');
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCTYPE)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
        }
        $doctypeJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $doctype
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMDocument', VmDom::PROP_DOCTYPE),
            $doctypeJit,
            JITVariable::TYPE_VALUE
        );
    }

    /**
     * Runtime: object class_id is a Document (shared with insertBefore routing).
     */
    public static function runtimeIsDocumentObject(Context $context, Value $obj): Value
    {
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $classId = $context->builder->load($context->builder->structGep($obj, $map['class_id']));
        $isDoc = $i1->constInt(0, false);
        foreach (['DOMDocument', 'Dom\\Document', 'Dom\\XMLDocument', 'Dom\\HTMLDocument'] as $className) {
            try {
                $expected = $objectType->lookup($className);
            } catch (\Throwable $e) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expected, false)
            );
            $isDoc = $context->builder->or($isDoc, $match);
        }

        return $isDoc;
    }

    /**
     * When {@code $child} is a DocumentType stand-in and {@code $document} is a
     * DOMDocument, pin it on {@code $document->doctype}.
     */
    public static function storeOnDocumentIfDoctype(
        Context $context,
        Value $document,
        Value $child
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_dt_maybe_store');
        $isDoc = self::runtimeIsDocumentObject($context, $document);
        $bbDoc = BasicBlockHelper::append($context, 'dom_dt_store_is_doc');
        $bbEnd = BasicBlockHelper::append($context, 'dom_dt_store_end');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEnd);

        $context->builder->positionAtEnd($bbDoc);
        $isDt = self::isDocumentTypeStandIn($context, $child);
        $bbYes = BasicBlockHelper::append($context, 'dom_dt_store_yes');
        $context->builder->branchIf($isDt, $bbYes, $bbEnd);
        $context->builder->positionAtEnd($bbYes);
        self::storeOnDocument($context, $document, $child);
        $context->builder->branch($bbEnd);
        $context->builder->positionAtEnd($bbEnd);
    }

    /**
     * @param list<Value> $parts
     */
    private static function concatMany(Context $context, array $parts): Value
    {
        $acc = $parts[0];
        $n = \count($parts);
        for ($i = 1; $i < $n; ++$i) {
            $acc = JitStringConcat::concat($context, $acc, $parts[$i], false);
        }

        return $acc;
    }
}
