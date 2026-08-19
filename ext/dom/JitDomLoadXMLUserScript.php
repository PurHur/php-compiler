<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LibxmlUseInternalErrorsRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMDocument::loadXML() (#18268, #19211, #23251). */
final class JitDomLoadXMLUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_TEXT_CONTENT = 'textContent';

    private static ?string $lastCompileTimeXml = null;

    /** @var \SplObjectStorage<JITVariable, string>|null Per-document compile-time XML (#27392). */
    private static ?\SplObjectStorage $xmlByReceiver = null;

    /** @var array<string, string> Token → XML when Variable identity rematerializes (#27392). */
    private static array $xmlByToken = [];

    private static int $xmlTokenSeq = 0;

    /** True when loadXML used the compile-time user-script path (no DomLoadXMLRuntime tree). */
    private static bool $lastLoadWasPureUserScript = false;

    /** Document class that owns PROP_DOCUMENT_ELEMENT for the last pure materialize (#27108). */
    private static ?string $lastDocumentClass = null;

    public static function lastCompileTimeXml(): ?string
    {
        return self::$lastCompileTimeXml;
    }

    /**
     * Compile-time XML for a specific DOMDocument receiver (stylesheet vs source doc) (#27392).
     */
    public static function compileTimeXmlFor(?JITVariable $document): ?string
    {
        if (null === $document) {
            return null;
        }
        if (null !== self::$xmlByReceiver && isset(self::$xmlByReceiver[$document])) {
            return self::$xmlByReceiver[$document];
        }
        $token = $document->compileTimeString;
        if (null !== $token && isset(self::$xmlByToken[$token])) {
            return self::$xmlByToken[$token];
        }

        return null;
    }

    /**
     * Pick a remembered loadXML() literal that is not the imported stylesheet (#27392).
     *
     * Method args often rematerialize as TYPE_VALUE boxes, so SplObjectStorage identity
     * and compileTimeString tokens do not survive from loadXML() to transformToXML().
     */
    public static function compileTimeXmlExcluding(?string $excludeXml): ?string
    {
        $seen = [];
        $candidates = [];
        foreach (self::$xmlByToken as $xml) {
            if (isset($seen[$xml])) {
                continue;
            }
            $seen[$xml] = true;
            if (null !== $excludeXml && $xml === $excludeXml) {
                continue;
            }
            $candidates[] = $xml;
        }
        if (1 === \count($candidates)) {
            return $candidates[0];
        }
        if (\count($candidates) > 1) {
            // Source document is typically loadXML'd before the stylesheet.
            return $candidates[0];
        }
        if (null !== self::$lastCompileTimeXml
            && (null === $excludeXml || self::$lastCompileTimeXml !== $excludeXml)
        ) {
            return self::$lastCompileTimeXml;
        }

        return null;
    }

    public static function lastLoadWasPureUserScript(): bool
    {
        return self::$lastLoadWasPureUserScript;
    }

    public static function lastDocumentClass(): ?string
    {
        return self::$lastDocumentClass;
    }

    /**
     * Mark the last load as pure user-script LLVM nodes (no DomRegistry tree).
     *
     * Shared by loadXML and loadHTML so {@see JitDomElementTextContent} reads
     * textContent from property slots instead of DomElementTextContentRuntime (#24121).
     */
    public static function markLastLoadPureUserScript(): void
    {
        self::$lastLoadWasPureUserScript = true;
    }

    public static function rememberCompileTimeXml(string $xml, string $documentClass = self::CLASS_DOCUMENT): void
    {
        self::$lastCompileTimeXml = $xml;
        self::$lastLoadWasPureUserScript = false;
        self::$lastDocumentClass = $documentClass;
        JitDomXPathRegisterUserScript::reset();
    }

    /** Bind compile-time XML to the loadXML() document receiver (#27392). */
    public static function rememberCompileTimeXmlFor(JITVariable $document, string $xml): void
    {
        self::$lastCompileTimeXml = $xml;
        if (null === self::$xmlByReceiver) {
            self::$xmlByReceiver = new \SplObjectStorage();
        }
        self::$xmlByReceiver[$document] = $xml;
        // Do not overwrite compileTimeString — object locals often carry the class name
        // ('DOMDocument') used elsewhere; index under a dedicated token only.
        $token = '__phpc_domxml_'.(++self::$xmlTokenSeq);
        self::$xmlByToken[$token] = $xml;
    }

    public static function rememberLivingDocumentClass(string $documentClass): void
    {
        self::$lastDocumentClass = $documentClass;
    }

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }

        // Non-default parse options (e.g. LIBXML_NOENT) need full VmDom::loadXML (#19796).
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $opt = $args[2]->compileTimeLong;
            if (null === $opt || 0 !== $opt) {
                return null;
            }
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $lit || '' === trim($lit)) {
            return null;
        }
        // Match VmDom::loadXML / libxml: drop leading UTF-8 BOM before the thin parse (#26565).
        $lit = VmDom::stripLeadingUtf8Bom($lit);
        $forParse = ltrim($lit);
        // Whitespace-before-BOM (or other non-'<' junk) must not use the thin path — Zend rejects
        // it via libxml; fall through to DomLoadXMLRuntime → VmDom::loadXML (#26565).
        if ('' === $forParse || '<' !== $forParse[0]) {
            return null;
        }
        // Incomplete / non-element markup (e.g. BOM+"<") — defer to real parser (#26565).
        if (1 !== preg_match('/<([a-zA-Z_][\w:.-]*)/', $forParse)) {
            return null;
        }
        $lit = $forParse;

        // Host well-formedness: thin path previously accepted unclosed roots like "<r>" (#29161).
        // When host libxml rejects, bake errors into the AOT libxml ring and return false.
        if (\extension_loaded('dom') && \class_exists(\DOMDocument::class, false)) {
            $prevInternal = null;
            if (\function_exists('libxml_use_internal_errors')) {
                $prevInternal = \libxml_use_internal_errors(true);
                if (\function_exists('libxml_clear_errors')) {
                    \libxml_clear_errors();
                }
            }
            $hostOk = false;
            $hostErrs = [];
            try {
                $hostDoc = new \DOMDocument();
                $hostOk = @$hostDoc->loadXML($lit);
                if (\function_exists('libxml_get_errors')) {
                    $hostErrs = \libxml_get_errors();
                }
            } catch (\Throwable) {
                $hostOk = false;
            } finally {
                if (null !== $prevInternal && \function_exists('libxml_use_internal_errors')) {
                    \libxml_use_internal_errors($prevInternal);
                }
            }
            if (!$hostOk) {
                return self::emitMalformedLoadFailure($context, $hostErrs);
            }
        }

        // Inter-element blank text is materialized as #text children at compile time (#27260).
        // LIBXML_NOBLANKS / non-zero options already fall through above (#20476). NestedJIT
        // DomLoadXMLRuntime cannot run VmDom::loadXML (no preg_match in lean helpers).

        self::rememberCompileTimeXmlFor($args[0], $lit);
        self::$lastDocumentClass = self::CLASS_DOCUMENT;
        self::markLastLoadPureUserScript();
        // Declare textContent/nodeValue on DOMElement so forWrite hasProperty skips
        // dynamic-property deprecation (hasProperty does not walk DOMNode; #23251).
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([self::PROP_TEXT_CONTENT, 'nodeValue', VmDom::PROP_USER_SCRIPT_INNER_XML] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_STRING);
            }
        }
        foreach (DomParseSimpleXmlIdsJitHelper::parseIndexedElementIds($lit) as $parsed) {
            self::materializeIndexedElement($context, $args[0], $parsed);
        }
        // Stable documentElement + inner markup so saveXML($node)/appendChild see children (#26757).
        self::materializeAndStoreDocumentElement($context, $args[0], $lit);

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt(1, false), $i32)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /**
     * Seed AOT libxml ring from host LibXMLError list and return false (#29161).
     *
     * @param list<\LibXMLError>|list<object> $hostErrs
     */
    private static function emitMalformedLoadFailure(Context $context, array $hostErrs): Value
    {
        $rows = [];
        foreach ($hostErrs as $err) {
            if (!\is_object($err)) {
                continue;
            }
            $rows[] = [
                'level' => (int) ($err->level ?? 0),
                'code' => (int) ($err->code ?? 0),
                'column' => (int) ($err->column ?? 0),
                'message' => (string) ($err->message ?? ''),
                'file' => (string) ($err->file ?? ''),
                'line' => (int) ($err->line ?? 0),
            ];
        }
        // Match common libxml premature-end when host returned no structured errors.
        if ([] === $rows) {
            $rows[] = [
                'level' => 3,
                'code' => 77,
                'column' => 0,
                'message' => 'Premature end of data in tag line 1',
                'file' => '',
                'line' => 1,
            ];
        }
        LibxmlUseInternalErrorsRuntime::emitSeedErrors($context, $rows);

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt(0, false), $i32)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /**
     * Materialize documentElement once at loadXML and pin it on the document (#26757).
     *
     * Rematerializing on every {@see JitDomDocumentElement::fetch} dropped mutations and
     * left PROP_USER_SCRIPT_INNER_XML empty so saveXML($node) emitted {@code <root></root>}.
     *
     * Also wires {@see VmDom::PROP_PARENT_NODE} → document so thin-AOT
     * {@code $isConnected} / getRootNode / contains parent-slot walks reach the
     * document (re-#29375 / #29434) — children already parent to the root via
     * {@see JitDomDocumentElement::syncChildrenFromXmlPublic}.
     */
    private static function materializeAndStoreDocumentElement(
        Context $context,
        JITVariable $receiver,
        string $xml
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_loadxml_us_document_element');
        $document = self::loadObjectArg($context, $receiver);
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $text = DomParseSimpleXmlJitHelper::rootTextContentArgv($xml);
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $element = JitDomCreateElement::materializeElementWithTextContent($context, $tag, $text);
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner);
        JitDomGetNodePath::storeOn($context, $element, self::CLASS_ELEMENT, '/'.$tag);
        JitDomCreateElement::storeAttributesPresence(
            $context,
            $element,
            [] !== DomParseSimpleXmlJitHelper::rootAttributesArgv($xml)
        );
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $xml, '/'.$tag);

        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        $elemJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCUMENT_ELEMENT),
            $elemJit,
            JITVariable::TYPE_OBJECT
        );
        // Same DOMElement parentNode layout as appendChild-to-document (#21687 / #29434).
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $docJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $document
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        // So getElementsByTagName()->item(0) returns the linked firstChild (#26752).
        DomUserScriptPinnedRootLlvm::pin($context, $element);
        self::pinUserScriptLoadSideEffects($context);
    }

    /**
     * @param array{tag: string, id: string, text: string} $parsed
     */
    private static function materializeIndexedElement(
        Context $context,
        JITVariable $receiver,
        array $parsed
    ): void {
        $document = self::loadObjectArg($context, $receiver);
        // Raw __object__* — invoke() boxes for call ABI (#29638) and must not feed id-map /
        // __phpc_dom_us_elem (#25119 / #29736).
        $element = JitDomCreateElement::materializeForUserScriptDocument(
            $context,
            $receiver,
            $parsed['tag'],
            $parsed['text']
        );
        self::storeElementInIdMap($context, $document, $parsed['id'], $element);
        $idStr = $context->builder->load($context->constantStringFromString($parsed['id']));
        DomUserScriptElementCacheLlvm::store($context, $document, $idStr, $element);
        self::pinUserScriptLoadSideEffects($context);
    }

    /** Keep id-map/cache writes when loadXML() return is discarded (#19211). */
    private static function pinUserScriptLoadSideEffects(Context $context): void
    {
        foreach (['__phpc_dom_us_ok', '__phpc_dom_us_id', '__phpc_dom_us_elem'] as $globalName) {
            $global = $context->module->getNamedGlobal($globalName);
            if (null === $global) {
                continue;
            }
            $context->builder->load($global);
        }
    }

    private static function storeElementInIdMap(
        Context $context,
        Value $document,
        string $idLit,
        Value $element
    ): void {
        self::ensureDocumentPropertyLayout($context);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        $mapVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP,
            $classId
        );
        $ht = HashTableHelper::readHashtableFromValueBox($context, $mapVar);
        $idStr = $context->builder->load($context->constantStringFromString($idLit));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $idStr,
            $element
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $propSlot = $objectType->propertySlotFor(
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $objectType->propertyStore($propSlot, $propVar, JITVariable::TYPE_VALUE);
        DomUserScriptElementCacheLlvm::store($context, $document, $idStr, $element);
    }

    private static function storeElementTextContent(Context $context, Value $element, string $textLit): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($classId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($classId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        $textStr = $context->builder->load($context->constantStringFromString($textLit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $textStr
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, self::PROP_TEXT_CONTENT),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function ensureDocumentPropertyLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            $objectType->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
        }
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMDocument::loadXML() receiver must be an object');
    }

    /**
     * True when libxml would see whitespace-only text between elements (xmlIsBlankNode candidates).
     *
     * @internal used by {@see JitDomLoadXML} to skip the user-script XML cache on full-tree loads (#20476)
     */
    public static function xmlContainsInterElementBlankText(string $xml): bool
    {
        return 1 === preg_match('/\>[ \t\r\n]+</', $xml);
    }
}
