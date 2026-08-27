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
use PHPLLVM\Builder;
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

    /**
     * DTD element → ID attr qName for the in-flight loadXML (#34696).
     *
     * @var array<string, string>
     */
    private static array $loadXmlIdAttrsByElement = [];

    /** @var array<string, true> xmlAddID first-wins set for the in-flight loadXML (#34696). */
    private static array $loadXmlRegisteredIds = [];

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
     * Flag a DOMDocument so document-wide saveXML dumps pinned slots (#33697).
     *
     * Used when appendChild installs a root on a document that never loadXML'd —
     * otherwise saveXML would replay {@see lastCompileTimeXml()} from another doc.
     */
    public static function markDocumentSaveXmlFromSlots(Context $context, JITVariable $document): void
    {
        $document->compileTimeDomSaveXmlFromSlots = true;
        if (!isset($context->namedVariableBindings) || !\is_array($context->namedVariableBindings)) {
            return;
        }
        $docVal = $document->value ?? null;
        foreach ($context->namedVariableBindings as $bound) {
            if (!$bound instanceof JITVariable) {
                continue;
            }
            if ($bound === $document || (null !== $docVal && $bound->value === $docVal)) {
                $bound->compileTimeDomSaveXmlFromSlots = true;
            }
        }
    }

    public static function documentSaveXmlFromSlots(?JITVariable $document): bool
    {
        return null !== $document && true === $document->compileTimeDomSaveXmlFromSlots;
    }

    /**
     * Replace the compile-time document root markup after DOMDocument::replaceChild
     * of documentElement so saveXML does not replay the old loadXML literal (#33379).
     */
    public static function refreshCompileTimeXmlReplaceRoot(
        string $newRootOuterXml,
        ?JITVariable $document = null
    ): void {
        $old = self::$lastCompileTimeXml;
        self::commitRefreshedCompileTimeXml($newRootOuterXml, $old, null, $document);
    }

    /**
     * Drop the loadXML literal so saveXML falls back to pinned documentElement slots (#33379).
     */
    public static function clearCompileTimeXmlForDocumentReplace(): void
    {
        self::$lastCompileTimeXml = null;
        self::$treeMutatedSinceLoad = true;
        self::$lastLoadWasPureUserScript = false;
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
        // Named aliases stamped by rememberCompileTimeXmlFor / propagate (#32978).
        // Prefer this over lastCompileTimeXml so a second document does not steal (#34630).
        if (null !== ($document->compileTimeDomLoadXml ?? null) && '' !== $document->compileTimeDomLoadXml) {
            return $document->compileTimeDomLoadXml;
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
     * Append createTextNode character data into a direct child of the document
     * element so C14N fold matches Zend after nested appendChild (#33000).
     *
     * Root-inner concat would produce {@code <a>1</a>x} instead of {@code <a>1x</a>}.
     * Peer {@see refreshCompileTimeXmlWithRootInner} / attribute mutate (#32981).
     */
    public static function refreshCompileTimeXmlAppendTextToChild(
        int $childIndex,
        string $rawText,
        bool $prepend = false
    ): void {
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
        $target = null;
        $i = 0;
        foreach ($root->childNodes as $node) {
            if ($i === $childIndex) {
                $target = $node;
                break;
            }
            ++$i;
        }
        if (!$target instanceof \DOMNode) {
            return;
        }
        $text = $doc->createTextNode($rawText);
        if ($prepend && null !== $target->firstChild) {
            $target->insertBefore($text, $target->firstChild);
        } else {
            $target->appendChild($text);
        }
        $new = @$doc->saveXML($root);
        if (!\is_string($new) || '' === $new) {
            return;
        }
        self::commitRefreshedCompileTimeXml($new, $xml);
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
     * Apply removeAttributeNS on the document element of the compile-time XML (#34257).
     *
     * loadXML seeds {@see VmDom::PROP_USER_SCRIPT_XMLNS_ATTR} from the open tag; the
     * createElement attr bag is empty, so bag-only remove never updates saveXML.
     */
    public static function refreshCompileTimeXmlRootAttributeRemoveNS(?string $namespace, string $localName): void
    {
        self::mutateCompileTimeXmlRootAttribute(static function (\DOMElement $root) use ($namespace, $localName): void {
            @$root->removeAttributeNS($namespace, $localName);
        });
    }

    /**
     * Push refreshed root open-tag attrs onto the element so node-scoped saveXML
     * matches Zend after removeAttribute / removeAttributeNS (#34257 / peer #33509).
     */
    public static function syncElementXmlnsAttrFromCompileTimeXml(
        Context $context,
        JITVariable $elementArg
    ): void {
        $xml = self::$lastCompileTimeXml;
        if (null === $xml || '' === trim($xml)) {
            return;
        }
        $stripped = preg_replace('/^\s*<\?xml[^?]*\?>\s*/i', '', trim($xml)) ?? trim($xml);
        $rootMarkup = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($stripped);
        if (null === $rootMarkup) {
            return;
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_loadxml_sync_xmlns_after_rmattr');
        $element = self::loadObjectArg($context, $elementArg);
        // parseElementMarkupArgv attrs may omit the leading space; storeUserScriptXmlnsAttr
        // expects the same shape loadXML seeded (leading space when non-empty).
        $attrs = $rootMarkup['attrs'];
        if ('' !== $attrs && !str_starts_with($attrs, ' ')) {
            $attrs = ' '.$attrs;
        }
        JitDomCreateElement::storeUserScriptXmlnsAttr($context, $element, $attrs);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $element);
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
        $document->compileTimeDomSaveXmlFromSlots = false;
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
        // loadXML with <!DOCTYPE> must stamp saveXML prefix — firstChild is only
        // documentElement, so the #34160 child walk would otherwise omit it (#34877).
        DomUserScriptDoctypeLlvm::rememberAttachedFromLoadXml($lit);
        // Declare textContent/nodeValue on DOMElement so forWrite hasProperty skips
        // dynamic-property deprecation (hasProperty does not walk DOMNode; #23251).
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([self::PROP_TEXT_CONTENT, 'nodeValue', VmDom::PROP_USER_SCRIPT_INNER_XML] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_STRING);
            }
        }
        // Register DTD / xml:id on the live syncChildren tree — not orphan map nodes (#34696).
        self::$loadXmlIdAttrsByElement = DomParseSimpleXmlIdsJitHelper::parseDoctypeIdAttributes($lit);
        self::$loadXmlRegisteredIds = [];
        // Stable documentElement + inner markup so saveXML($node)/appendChild see children (#26757).
        // Also pins $doc->doctype DocumentType stand-in (#34887 / peer #28940).
        self::materializeAndStoreDocumentElement($context, $args[0], $lit);
        self::$loadXmlIdAttrsByElement = [];
        self::$loadXmlRegisteredIds = [];

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
        // Grow DOMElement stand-in layout (name/publicId/systemId) BEFORE allocating
        // documentElement — otherwise DocumentType::materialize leaves earlier nodes
        // undersized and SIGSEGVs (#34887 / peer #33565).
        self::storeDoctypeProperty($context, $document, $xml);
        self::storeEncodingFromXml($context, $document, $xml);
        // Peer encoding (#34919): construct defaults stay until loadXML seeds the decl (#34951).
        self::storeXmlVersionAndStandaloneFromXml($context, $document, $xml);
        self::storeDocumentUriCwd($context, $document);
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $text = DomParseSimpleXmlJitHelper::rootTextContentArgv($xml);
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        // Root open-tag attrs for node-scoped saveXML (peer child sync #33014).
        $rootMarkup = DomParseSimpleXmlJitHelper::parseElementMarkupArgv(
            preg_replace('/^\\s*<\\?xml[^?]*\\?>\\s*/i', '', trim($xml)) ?? trim($xml)
        );
        $rootOpen = '';
        if (null !== $rootMarkup) {
            // Rebuild open-tag shape for xmlns scope + attr suffix (#34924).
            $rootOpen = '<'.$tag.$rootMarkup['attrs'].'>';
        }
        $element = JitDomDocumentElement::materializeElementFromXmlTag(
            $context,
            $tag,
            $text,
            $rootOpen
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner);
        if (null !== $rootMarkup && '' !== $rootMarkup['attrs']) {
            JitDomCreateElement::storeUserScriptXmlnsAttr($context, $element, $rootMarkup['attrs']);
        }
        JitDomGetNodePath::storeOn($context, $element, self::CLASS_ELEMENT, '/'.$tag);
        JitDomCreateElement::storeAttributesPresence(
            $context,
            $element,
            DomParseSimpleXmlJitHelper::rootAttributesArgv($xml)
        );
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $xml, '/'.$tag, $document);
        self::registerLiveElementIdFromMarkup(
            $context,
            $document,
            $element,
            $tag,
            null !== $rootMarkup ? $rootMarkup['attrs'] : ''
        );

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
        // Seed Document first/last/childNodes so ChildNode::before/after and
        // insertBefore see the root in the sibling chain (#34160 / peer #32743).
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_FIRST_CHILD),
            $elemJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_LAST_CHILD),
            $elemJit,
            JITVariable::TYPE_VALUE
        );
        JitDomDocumentElement::storeChildNodesLength(
            $context,
            $document,
            1,
            $element,
            null,
            self::CLASS_DOCUMENT
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
        // Seed ownerDocument so foreign-mutation Wrong Document resolves without
        // reading an unset slot (#33937 / peer createElement #21687).
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_OWNER_DOCUMENT),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        // So getElementsByTagName()->item(0) returns the linked firstChild (#26752).
        DomUserScriptPinnedRootLlvm::pin($context, $element);
        self::pinUserScriptLoadSideEffects($context);
    }

    /**
     * Store DOMDocument::$xmlVersion / $xmlStandalone (+ Level-3 aliases) from the
     * XML declaration (php-src document.c; #34951 leftover of #34916 / #34894).
     *
     * Construct seeds defaults (1.0 / false). Thin loadXML must overwrite from
     * {@code <?xml …?>} like {@see VmDom::parseXmlDeclaration} / loadXML.
     */
    private static function storeXmlVersionAndStandaloneFromXml(
        Context $context,
        Value $document,
        string $xml
    ): void {
        $version = '1.0';
        $standalone = false;
        if (preg_match('/^\s*<\?xml\s+([^?]*)\?>/s', $xml, $match)) {
            $attrs = $match[1];
            if (preg_match('/version\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $versionMatch)) {
                $version = $versionMatch[2];
            }
            if (preg_match('/standalone\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $standaloneMatch)) {
                $standalone = 'yes' === strtolower($standaloneMatch[2]);
            }
        }

        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        $str = $context->builder->load($context->constantStringFromString($version));
        foreach ([VmDom::PROP_XML_VERSION, VmDom::PROP_VERSION] as $prop) {
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, JITVariable::TYPE_STRING);
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $propVar = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $owned
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, $prop),
                $propVar,
                JITVariable::TYPE_STRING
            );
        }

        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        foreach ([VmDom::PROP_XML_STANDALONE, VmDom::PROP_STANDALONE] as $prop) {
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, JITVariable::TYPE_VALUE);
            }
            $box = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $box,
                $context->builder->zext($i1->constInt($standalone ? 1 : 0, false), $i32)
            );
            $propVar = new JITVariable(
                $context,
                JITVariable::TYPE_VALUE,
                JITVariable::KIND_VARIABLE,
                $box
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, $prop),
                $propVar,
                JITVariable::TYPE_VALUE
            );
        }
    }

    /**
     * Store DOMDocument::$encoding from the XML declaration (php-src document.c; #34919).
     *
     * Replaces MetaProps compile-time stamp so writes to $encoding stick and loadXML
     * reads still match Zend (#34894).
     */
    private static function storeEncodingFromXml(
        Context $context,
        Value $document,
        string $xml
    ): void {
        $enc = null;
        if ('' !== $xml
            && 1 === preg_match('/<\?xml[^>]*encoding\s*=\s*["\']([^"\']+)["\']/i', $xml, $m)
        ) {
            $enc = (string) $m[1];
        }
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_ENCODING)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_ENCODING, JITVariable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        if (null === $enc) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $box)
            );
        } else {
            $str = $context->builder->load($context->constantStringFromString($enc));
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $box),
                $owned
            );
        }
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_ENCODING),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    /**
     * Store DOMDocument::$documentURI to CWD after loadXML(string) (php-src document.c; #34925).
     *
     * Replaces MetaProps compile-time cwd stamp so writes to $documentURI stick and
     * loadXML reads still match Zend (#34894 / #34904). Trailing slash matches Zend
     * in the pinned container.
     */
    private static function storeDocumentUriCwd(Context $context, Value $document): void
    {
        $cwd = \getcwd();
        if (false === $cwd || '' === $cwd) {
            return;
        }
        if ('/' !== substr($cwd, -1)) {
            $cwd .= '/';
        }
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_URI)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_URI, JITVariable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $str = $context->builder->load($context->constantStringFromString($cwd));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $box),
            $owned
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCUMENT_URI),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    /**
     * Initialize DOMDocument::$doctype for user-script loadXML (#34887).
     *
     * No {@code <!DOCTYPE>} → leave unset; {@see JitDomDocumentDoctype} returns null.
     * Explicit doctype → DocumentType stand-in with name/publicId/systemId (peer
     * {@see JitDomHtmlDocumentCreateFromString::storeDoctypeProperty} / #28940).
     *
     * Must run before documentElement allocate: {@see JitDomCreateDocumentType::materialize}
     * grows the DOMElement stand-in class; allocating the tree first leaves it undersized
     * (#33565).
     *
     * {@see JitDomCreateDocumentType::materialize} calls {@see DomUserScriptDoctypeLlvm::rememberCreate}
     * (clears attached); re-{@see DomUserScriptDoctypeLlvm::markAttached} so saveXML prefix (#34877) stays.
     */
    private static function storeDoctypeProperty(
        Context $context,
        Value $document,
        string $xml
    ): void {
        $parsed = DomUserScriptDoctypeLlvm::parseFromXml($xml);
        if (null === $parsed) {
            return;
        }
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        // PROP_DOCTYPE is in DOMDocument allocate() layout (#34887 / Object_.php).
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCTYPE)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
        }
        $doctype = JitDomCreateDocumentType::materialize(
            $context,
            $parsed['name'],
            $parsed['publicId'],
            $parsed['systemId']
        );
        $doctypeJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $doctype
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCTYPE),
            $doctypeJit,
            JITVariable::TYPE_VALUE
        );
        DomUserScriptDoctypeLlvm::markAttached();
    }

    /**
     * Register a live loadXML tree node in the document id map (#34696).
     *
     * Called from {@see JitDomDocumentElement::syncChildrenFromXml} / documentElement
     * materialize so getElementById returns the same object as childNodes (Zend/VM).
     */
    public static function registerLiveElementIdFromOpenTag(
        Context $context,
        ?Value $document,
        Value $element,
        string $tag,
        string $openTag
    ): void {
        if (null === $document || '' === $openTag) {
            return;
        }
        $suffix = DomParseSimpleXmlJitHelper::attrSuffixFromOpenTagArgv($openTag);
        $attrs = '' === $suffix ? [] : VmDom::parseMarkupAttributes($suffix);
        self::registerLiveElementIdFromAttrMap($context, $document, $element, $tag, $attrs);
    }

    /**
     * @param array<string, string> $attrs
     */
    public static function registerLiveElementIdFromMarkup(
        Context $context,
        Value $document,
        Value $element,
        string $tag,
        string $attrSuffix
    ): void {
        if ('' === $attrSuffix) {
            $attrs = [];
        } else {
            $attrs = VmDom::parseMarkupAttributes($attrSuffix);
        }
        self::registerLiveElementIdFromAttrMap($context, $document, $element, $tag, $attrs);
    }

    /**
     * @param array<string, string> $attrs
     */
    private static function registerLiveElementIdFromAttrMap(
        Context $context,
        Value $document,
        Value $element,
        string $tag,
        array $attrs
    ): void {
        $idVal = null;
        // Attr name whose libxml atype becomes XML_ATTRIBUTE_ID (for isId; #34821).
        $idAttrName = null;
        $idAttr = self::$loadXmlIdAttrsByElement[$tag]
            ?? self::$loadXmlIdAttrsByElement[strtolower($tag)]
            ?? null;
        if (null !== $idAttr && isset($attrs[$idAttr]) && '' !== $attrs[$idAttr]) {
            $idVal = $attrs[$idAttr];
            $idAttrName = $idAttr;
        } elseif (isset($attrs['xml:id']) && '' !== $attrs['xml:id']) {
            $idVal = $attrs['xml:id'];
            $idAttrName = 'xml:id';
        }
        if (null === $idVal || '' === $idVal) {
            return;
        }
        if (isset(self::$loadXmlRegisteredIds[$idVal])) {
            // xmlAddID first-wins — duplicate leaves atype unset / isId false (#25274).
            return;
        }
        self::$loadXmlRegisteredIds[$idVal] = true;
        // Thin-AOT isId() reads Attr-cache idBearing flags (peer setIdAttribute #29884),
        // not DomRegistry — stamp when DTD / xml:id actually registers (#34821).
        if (null !== $idAttrName) {
            DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', $idAttrName, true);
        }
        self::storeElementInIdMap($context, $document, $idVal, $element);
        $idStr = $context->builder->load($context->constantStringFromString($idVal));
        // First registrant keeps the single-slot cache; later IDs live in the map (#34696).
        DomUserScriptElementCacheLlvm::storeFirstWins($context, $document, $idStr, $element);
        self::pinUserScriptLoadSideEffects($context);
    }

    /**
     * @param array{tag: string, id: string, text: string} $parsed
     *
     * @deprecated Orphan id-map materialize removed — live tree registration (#34696).
     */
    private static function materializeIndexedElement(
        Context $context,
        JITVariable $receiver,
        array $parsed
    ): void {
        unset($context, $receiver, $parsed);
        throw new \LogicException('materializeIndexedElement retired — use registerLiveElementIdFromOpenTag (#34696)');
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

    /** Register or overwrite one id → element in PROP_ELEMENT_ID_MAP (#34696 / #19870 rebind). */
    public static function storeElementInIdMap(
        Context $context,
        Value $document,
        string $idLit,
        Value $element
    ): void {
        if ('' === $idLit) {
            return;
        }
        self::storeElementInIdMapFromValue(
            $context,
            $document,
            $context->builder->load($context->constantStringFromString($idLit)),
            $element
        );
    }

    /** Runtime id string → element in PROP_ELEMENT_ID_MAP (multi-id setIdAttribute; #34696). */
    public static function storeElementInIdMapFromValue(
        Context $context,
        Value $document,
        Value $idStr,
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
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $idStr,
            $element
        );
        self::writeElementIdMapHashtable($context, $document, $ht);
    }

    /** xmlAddID first-wins — do not replace an existing id map entry (#34050 / #25275). */
    public static function storeElementInIdMapFromValueFirstWins(
        Context $context,
        Value $document,
        Value $idStr,
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
        $foundVar = HashTableHelper::readStringKeyToValueBox($context, $ht, $idStr);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $foundVar);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $already = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_OBJECT, false)
        );
        $skip = BasicBlockHelper::append($context, 'dom_idmap_fw_skip');
        $write = BasicBlockHelper::append($context, 'dom_idmap_fw_write');
        $done = BasicBlockHelper::append($context, 'dom_idmap_fw_done');
        $context->builder->branchIf($already, $skip, $write);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($write);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $idStr,
            $element
        );
        self::writeElementIdMapHashtable($context, $document, $ht);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /** Drop one id key from PROP_ELEMENT_ID_MAP after setAttribute/removeAttribute rebind (#19870). */
    public static function removeElementFromIdMap(
        Context $context,
        Value $document,
        string $idLit
    ): void {
        if ('' === $idLit) {
            return;
        }
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
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $idStr
        );
        self::writeElementIdMapHashtable($context, $document, $ht);
    }

    private static function writeElementIdMapHashtable(
        Context $context,
        Value $document,
        Value $ht
    ): void {
        $objectType = $context->type->object;
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
