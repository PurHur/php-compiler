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

    /**
     * BOM-stripped loadXML source including leading whitespace.
     * libxml xmlGetLineNo counts those newlines; $lastCompileTimeXml is ltrim'd for parse (#32489).
     */
    private static ?string $lastCompileTimeXmlSource = null;

    /** @var \SplObjectStorage<JITVariable, string>|null Per-document compile-time XML (#27392). */
    private static ?\SplObjectStorage $xmlByReceiver = null;

    /** @var array<string, string> Token → XML when Variable identity rematerializes (#27392). */
    private static array $xmlByToken = [];

    private static int $xmlTokenSeq = 0;

    /** True when loadXML used the compile-time user-script path (no DomLoadXMLRuntime tree). */
    private static bool $lastLoadWasPureUserScript = false;

    /** Context from the last {@see rememberCompileTimeXmlFor} — for mutation refresh (#32978). */
    private static ?Context $lastRememberContext = null;

    /** CV name of the in-flight loadXML() receiver (METHODCALL_INIT) (#32987). */
    private static ?string $pendingLoadXmlReceiverVarName = null;

    public static function setPendingLoadXmlReceiverVarName(?string $name): void
    {
        self::$pendingLoadXmlReceiverVarName = (null !== $name && '' !== $name) ? $name : null;
    }

    /**
     * Set when appendChild/insertBefore/replaceChild/removeChild (etc.) rewrote the
     * live tree after a pure loadXML — C14N/C14NFile must not fold the original literal (#32972).
     */
    private static bool $treeMutatedSinceLoad = false;

    /** Document class that owns PROP_DOCUMENT_ELEMENT for the last pure materialize (#27108). */
    private static ?string $lastDocumentClass = null;

    public static function lastCompileTimeXml(): ?string
    {
        return self::$lastCompileTimeXml;
    }

    /**
     * Compile-time XML safe for C14N fold when the receiver is not bound (#32978).
     *
     * {@see lastCompileTimeXml()} is the *last* loadXML literal — with two documents
     * that steals C14N of the earlier document. Only fall back when a single distinct
     * literal is remembered.
     */
    public static function unambiguousCompileTimeXml(): ?string
    {
        $seen = [];
        foreach (self::$xmlByToken as $xml) {
            $seen[$xml] = true;
        }
        if (\count($seen) > 1) {
            return null;
        }
        if (1 === \count($seen)) {
            return array_key_first($seen);
        }

        return self::$lastCompileTimeXml;
    }

    /** Original loadXML bytes for xmlGetLineNo (php-src ext/dom/node.c) (#32489). */
    public static function lastCompileTimeXmlSource(): ?string
    {
        return self::$lastCompileTimeXmlSource ?? self::$lastCompileTimeXml;
    }

    public static function treeMutatedSinceLoad(): bool
    {
        return self::$treeMutatedSinceLoad;
    }

    /** Call after LiveSlots / InnerXml tree mutations (#32972). */
    public static function markTreeMutatedSinceLoad(): void
    {
        self::$treeMutatedSinceLoad = true;
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
        self::$treeMutatedSinceLoad = false;
    }

    /**
     * Rebuild {@see $lastCompileTimeXml} after a root-inner rewrite so C14N fold
     * sees appendChild/insertBefore/replaceChild/removeChild (#32972 / #32978).
     */
    public static function refreshCompileTimeXmlWithRootInner(string $newInner, ?JITVariable $node = null): void
    {
        $xml = $node?->compileTimeDomLoadXml ?? self::$lastCompileTimeXml;
        if (null === $xml || '' === trim($xml)) {
            return;
        }
        $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($xml);
        if (null === $parsed) {
            return;
        }
        $tag = $parsed['tag'];
        $attrs = $parsed['attrs'];
        self::commitRefreshedCompileTimeXml(
            '<'.$tag.$attrs.'>'.$newInner.'</'.$tag.'>',
            $xml,
            $newInner,
            $node
        );
    }

    /**
     * Apply setAttribute on the document element of the compile-time XML so C14N
     * fold sees the new attr (#32981; peer #32972 root-inner refresh).
     */
    public static function refreshCompileTimeXmlRootAttributeSet(string $name, string $value): void
    {
        self::mutateCompileTimeXmlRootAttribute(static function (\DOMElement $root) use ($name, $value): void {
            @$root->setAttribute($name, $value);
        });
    }

    /**
     * Apply removeAttribute on the document element of the compile-time XML (#32981).
     */
    public static function refreshCompileTimeXmlRootAttributeRemove(string $name): void
    {
        self::mutateCompileTimeXmlRootAttribute(static function (\DOMElement $root) use ($name): void {
            @$root->removeAttribute($name);
        });
    }

    /**
     * @param callable(\DOMElement):void $mutate
     */
    private static function mutateCompileTimeXmlRootAttribute(callable $mutate): void
    {
        $xml = self::$lastCompileTimeXml;
        if (null === $xml || '' === trim($xml)) {
            return;
        }
        if (!class_exists(\DOMDocument::class, false) && !class_exists(\DOMDocument::class)) {
            return;
        }
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return;
        }
        $root = $doc->documentElement;
        if (!$root instanceof \DOMElement) {
            return;
        }
        $mutate($root);
        $new = @$doc->saveXML($root);
        if (!\is_string($new) || '' === $new) {
            return;
        }
        self::commitRefreshedCompileTimeXml($new);
    }

    /** Keep lastCompileTimeXml + per-receiver/token maps in sync after a fold refresh. */
    private static function commitRefreshedCompileTimeXml(
        string $newXml,
        ?string $oldXml = null,
        ?string $newInner = null,
        ?JITVariable $node = null
    ): void {
        $old = $oldXml ?? self::$lastCompileTimeXml;
        self::$lastCompileTimeXml = $newXml;
        // Fold may use the refreshed literal.
        self::$treeMutatedSinceLoad = false;
        self::$lastLoadWasPureUserScript = true;
        if (null !== $old) {
            foreach (self::$xmlByToken as $token => $xml) {
                if ($xml === $old) {
                    self::$xmlByToken[$token] = $newXml;
                }
            }
            if (null !== self::$xmlByReceiver) {
                foreach (self::$xmlByReceiver as $receiver) {
                    if (self::$xmlByReceiver[$receiver] === $old) {
                        self::$xmlByReceiver[$receiver] = $newXml;
                        $receiver->compileTimeDomLoadXml = $newXml;
                    }
                }
            }
            self::rewriteCompileTimeDomLoadXmlLiteral($old, $newXml, $newInner, $node);
        }
    }

    /**
     * Rewrite {@see JITVariable::$compileTimeDomLoadXml} from $oldXml → $newXml on the
     * mutation receiver and any named locals that still hold $oldXml (#32972 / #32978).
     */
    private static function rewriteCompileTimeDomLoadXmlLiteral(
        string $oldXml,
        string $newXml,
        ?string $newInner,
        ?JITVariable $node
    ): void {
        if (null !== $node) {
            $node->compileTimeDomLoadXml = $newXml;
            if (null !== $newInner) {
                $node->compileTimeDomInnerXml = $newInner;
            }
        }
        if (null === self::$lastRememberContext) {
            return;
        }
        $context = self::$lastRememberContext;
        if (!isset($context->namedVariableBindings) || !\is_array($context->namedVariableBindings)) {
            return;
        }
        foreach ($context->namedVariableBindings as $bound) {
            if (!$bound instanceof JITVariable) {
                continue;
            }
            if ($bound->compileTimeDomLoadXml === $oldXml) {
                $bound->compileTimeDomLoadXml = $newXml;
                if (null !== $newInner) {
                    $bound->compileTimeDomInnerXml = $newInner;
                }
            }
        }
    }

    /**
     * @deprecated Use {@see markTreeMutatedSinceLoad()} or {@see refreshCompileTimeXmlWithRootInner()}.
     */
    public static function invalidateCompileTimeXmlFold(): void
    {
        self::markTreeMutatedSinceLoad();
    }

    public static function rememberCompileTimeXml(string $xml, string $documentClass = self::CLASS_DOCUMENT, ?string $sourceXml = null): void
    {
        self::$lastCompileTimeXml = $xml;
        self::$lastCompileTimeXmlSource = $sourceXml ?? $xml;
        self::$lastLoadWasPureUserScript = false;
        self::$treeMutatedSinceLoad = false;
        self::$lastDocumentClass = $documentClass;
        JitDomXPathRegisterUserScript::reset();
    }

    /** Bind compile-time XML to the loadXML() document receiver (#27392 / #32978). */
    public static function rememberCompileTimeXmlFor(
        Context $context,
        JITVariable $document,
        string $xml,
        ?string $sourceXml = null
    ): void {
        self::$lastCompileTimeXml = $xml;
        self::$lastCompileTimeXmlSource = $sourceXml ?? $xml;
        self::$treeMutatedSinceLoad = false;
        self::$lastRememberContext = $context;
        $document->compileTimeDomLoadXml = $xml;
        self::propagateCompileTimeDomLoadXmlToAliases($context, $document, $xml);
        if (null === self::$xmlByReceiver) {
            self::$xmlByReceiver = new \SplObjectStorage();
        }
        self::$xmlByReceiver[$document] = $xml;
        // Do not overwrite compileTimeString — object locals often carry the class name
        // ('DOMDocument') used elsewhere; index under a dedicated token only.
        $token = '__phpc_domxml_'.(++self::$xmlTokenSeq);
        self::$xmlByToken[$token] = $xml;
    }

    /** Copy {@see JITVariable::$compileTimeDomLoadXml} onto named document locals (#32978 / #32987). */
    private static function propagateCompileTimeDomLoadXmlToAliases(
        Context $context,
        JITVariable $document,
        string $xml
    ): void {
        $pendingName = self::$pendingLoadXmlReceiverVarName;
        self::$pendingLoadXmlReceiverVarName = null;
        if (!isset($context->namedVariableBindings) || !\is_array($context->namedVariableBindings)) {
            return;
        }
        $docVal = $document->value ?? null;
        foreach ($context->namedVariableBindings as $name => $bound) {
            if (!$bound instanceof JITVariable) {
                continue;
            }
            $stamp = false;
            if ($bound === $document) {
                $stamp = true;
            } elseif (null !== $docVal && $bound->value === $docVal) {
                $stamp = true;
            } elseif (null !== $pendingName && $name === $pendingName) {
                // Prefer the METHODCALL receiver CV over sibling DOMDocument locals (#32987).
                $stamp = true;
            }
            if (!$stamp) {
                continue;
            }
            $bound->compileTimeDomLoadXml = $xml;
            if (null === self::$xmlByReceiver) {
                self::$xmlByReceiver = new \SplObjectStorage();
            }
            self::$xmlByReceiver[$bound] = $xml;
        }
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
        // Keep original bytes for xmlGetLineNo; parse still uses ltrim (#32489).
        $sourceXml = $lit;
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

        self::rememberCompileTimeXmlFor($context, $args[0], $lit, $sourceXml);
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
            DomParseSimpleXmlJitHelper::rootAttributesArgv($xml)
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
