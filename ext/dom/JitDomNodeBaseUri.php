<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeBaseUriRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::$baseURI on all node receivers (#34904 / maintainer_gap_dom_base_uri).
 *
 * User-script AOT reads ownerDocument::$documentURI (loadXML slots). Registry nodes use
 * {@see VmDom::readBaseUri} via NestedJIT.
 *
 * php-src: ext/dom/node.c dom_node_base_uri_read
 */
final class JitDomNodeBaseUri
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    public static function isDomNodeBaseUriProperty(string $classLc, string $propLc): bool
    {
        if ('baseuri' !== strtolower($propLc)) {
            return false;
        }
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));

        return str_starts_with($classLc, 'dom');
    }

    public static function fetch(Object_ $objectType, Value $obj, string $className): JITVariable
    {
        $context = $objectType->jitContext();
        $classLc = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
        if ('domdocument' === $classLc || 'dom\\document' === $classLc || 'dom\\xmldocument' === $classLc) {
            return self::fetchDocumentUri($objectType, $obj);
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::fetchUserScriptViaOwnerDocument($objectType, $obj);
        }

        DomNodeBaseUriRuntime::ensureLinked($context);
        $str = $context->builder->call(
            $context->lookupFunction(DomNodeBaseUriRuntime::ABI_NAME),
            $obj
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $str
        );
    }

    private static function fetchDocumentUri(Object_ $objectType, Value $obj): JITVariable
    {
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);

        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_DOCUMENT,
            VmDom::PROP_DOCUMENT_URI,
            $classId,
            false
        );
    }

    /** Element/text nodes inherit the document URI from ownerDocument (#34904). */
    private static function fetchUserScriptViaOwnerDocument(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_baseuri_userscript');
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        $elClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($elClassId, VmDom::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($elClassId, VmDom::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_URI)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_URI, JITVariable::TYPE_VALUE);
        }

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $obj, 'dom_baseuri_is_doc');
        $bbDoc = BasicBlockHelper::append($context, 'dom_baseuri_doc');
        $bbNode = BasicBlockHelper::append($context, 'dom_baseuri_node');
        $bbDone = BasicBlockHelper::append($context, 'dom_baseuri_done');
        $context->builder->branchIf($isDoc, $bbDoc, $bbNode);

        $context->builder->positionAtEnd($bbDoc);
        $docUriVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_DOCUMENT,
            VmDom::PROP_DOCUMENT_URI,
            $docClassId,
            false
        );
        $context->builder->store(
            self::stringPtrFromVar($context, $docUriVar),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNode);
        $ownerVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ELEMENT,
            VmDom::PROP_OWNER_DOCUMENT,
            $elClassId,
            false
        );
        $ownerRaw = JitValueBox::valuePtrFromVariable($context, $ownerVar);
        $ownerNull = JitNestedHelperCoerce::isHelperResultNull($context, $ownerRaw);
        $bbEmpty = BasicBlockHelper::append($context, 'dom_baseuri_empty');
        $bbOwner = BasicBlockHelper::append($context, 'dom_baseuri_owner');
        $context->builder->branchIf($ownerNull, $bbEmpty, $bbOwner);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store($emptyStr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOwner);
        $ownerObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $ownerRaw)
        );
        $ownerUriVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $ownerObj,
            self::CLASS_DOCUMENT,
            VmDom::PROP_DOCUMENT_URI,
            $docClassId,
            false
        );
        $context->builder->store(
            self::stringPtrFromVar($context, $ownerUriVar),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $str = $context->builder->load($resultSlot);

        return new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $str
        );
    }

    private static function stringPtrFromVar(\PHPCompiler\JIT\Context $context, JITVariable $var): Value
    {
        if (JITVariable::TYPE_STRING === $var->type) {
            return $var->value;
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $var)
        );
    }
}
