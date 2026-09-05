<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;

/**
 * DOM compile-time tag / leaf / InnerXml metadata propagation (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code propagateDomImportNodeCompileTimeTag}
 * through {@code bindCompileTimeDomTextData} (folded json_encode/serialize/str_repeat
 * peers stay in the hub) so JIT.php shrinks for gen-0 split-TU iterability.
 *
 * php-src: ext/dom node copy/import and character-data factories
 * (`ext/dom/document.c`, `ext/dom/node.c`, `ext/dom/text.c`) — compile-time meta only;
 * no new C ABI.
 */
trait DomCompileTimeTagMeta
{
    /**
     * Stamp importNode($src, $deep) tag/inner on the result so appendChild can refresh C14N (#32987).
     *
     * Thin-AOT importNode materializes a user-script element but the ASSIGN result Variable
     * previously had no compileTimeDom* metadata — ParentNode sync then skipped the child
     * and left the fold on the pre-mutation loadXML literal.
     *
     * `$deep` must gate InnerXml: shallow importNode must not re-stamp source children
     * onto the result (php-src xmlDocCopyNode deep=0; #33097).
     * Attributes are always copied (#33362).
     * Text imports stamp compileTimeDomTextData (#35043).
     * Comment / CDATA / PI imports stamp leaf tag + TextData — not `#text` (#35098).
     *
     * @param array<int, Variable> $callArgs
     */
    private function propagateDomImportNodeCompileTimeTag(Operand $result, array $callArgs): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentImportNode)) {
            return;
        }
        $src = $callArgs[1] ?? null;
        if (!$src instanceof Variable) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $materializedTag = $this->context->extensionLowering->domCompileTime?->lastMaterializedImportTagName();
        $srcTag = $src->compileTimeDomTagName;
        $leafTag = $materializedTag
            ?? (
                \in_array($srcTag, ['#text', '#comment', '#cdata-section', '#pi'], true)
                    ? $srcTag
                    : null
            );
        $textData = $src->compileTimeDomTextData
            ?? (
                '#text' === ($srcTag ?? null)
                    ? $this->context->extensionLowering->domCompileTime?->lastMaterializedTextData()
                    : null
            )
            ?? (
                '#text' === ($materializedTag ?? null)
                    ? $this->context->extensionLowering->domCompileTime?->lastMaterializedTextData()
                    : null
            );
        // Leaf imports: stamp CharacterData body + discriminator (not element tag).
        if (null !== $leafTag && \in_array($leafTag, ['#text', '#comment', '#cdata-section', '#pi'], true)) {
            if (null !== $textData) {
                $this->bindCompileTimeDomTextData($result, $textData);
            }
            $resultVar->compileTimeDomTagName = '#text' === $leafTag ? null : $leafTag;
            $resultVar->compileTimeDomInnerXml = null;
            $resultVar->compileTimeDomLoadXml = null;
            $resultVar->compileTimeDomAttributes = null;

            return;
        }
        // Legacy text-only stamp when source has TextData but no leaf tag (#35043).
        if (null !== $textData && (null === $srcTag || '' === $srcTag || '#text' === $srcTag)) {
            $this->bindCompileTimeDomTextData($result, $textData);
            $resultVar->compileTimeDomTagName = null;
            $resultVar->compileTimeDomInnerXml = null;
            $resultVar->compileTimeDomLoadXml = null;
            $resultVar->compileTimeDomAttributes = null;

            return;
        }
        $tag = $srcTag;
        if (null === $tag || '' === $tag) {
            $tag = $materializedTag;
        }
        if (null === $tag || '' === $tag || '#text' === $tag
            || \in_array($tag, ['#comment', '#cdata-section', '#pi'], true)
        ) {
            return;
        }
        $deep = false;
        $deepArg = $callArgs[2] ?? null;
        if ($deepArg instanceof Variable) {
            if (null !== $deepArg->compileTimeLong) {
                $deep = 0 !== $deepArg->compileTimeLong;
            } elseif (null !== $deepArg->compileTimeString) {
                $deep = '1' === $deepArg->compileTimeString
                    || 'true' === strtolower($deepArg->compileTimeString);
            }
        }
        $resultVar->compileTimeDomTagName = $tag;
        $resultVar->compileTimeDomInnerXml = $deep ? ($src->compileTimeDomInnerXml ?? '') : '';
        // Imported node is owned by the destination document — do not keep the source
        // document's loadXML stamp (C14N / refresh must use the append receiver).
        $resultVar->compileTimeDomLoadXml = null;
        if (null !== $src->compileTimeDomNodePath) {
            $resultVar->compileTimeDomNodePath = $src->compileTimeDomNodePath;
        }
        if (null !== $src->compileTimeDomGeiHtmlHit) {
            $resultVar->compileTimeDomGeiHtmlHit = $src->compileTimeDomGeiHtmlHit;
        }
        // Stamp attrs for appendChild INNER_XML sync (compileTimeChildElementMarkup; #33362).
        $attrs = $this->context->extensionLowering->domCompileTime?->compileTimeAttributesFor($src, $tag);
        if (null !== $attrs && [] !== $attrs) {
            $resultVar->compileTimeDomAttributes = $attrs;
        } elseif (null !== $src->compileTimeDomAttributes) {
            $resultVar->compileTimeDomAttributes = $src->compileTimeDomAttributes;
        }
        if ((null !== $this->context->extensionLowering->domCompileTime && $this->context->extensionLowering->domCompileTime->isDocumentFragmentTag($tag))) {
            $resultVar->compileTimeDomNodeListLength = $deep
                ? (int) ($src->compileTimeDomNodeListLength ?? 0)
                : 0;
        }
    }

    /**
     * Remember createElement('lit') tag on the result Variable for ParentNode saveXML (#26765).
     *
     * @param array<int, Variable> $callArgs
     */
    private function propagateDomCreateElementCompileTimeTag(Operand $result, array $callArgs): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentCreateElement)) {
            return;
        }
        $nameArg = $callArgs[1] ?? null;
        if (!$nameArg instanceof Variable) {
            return;
        }
        $tag = $nameArg->compileTimeString;
        if (null === $tag || '' === $tag) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $resultVar->compileTimeDomTagName = $tag;
        $resultVar->compileTimeDomElementId = $this->context->extensionLowering->domCompileTime?->nextCreateElementId($tag);
        $valueArg = $callArgs[2] ?? null;
        $inner = '';
        if ($valueArg instanceof Variable && null !== $valueArg->compileTimeString) {
            $inner = htmlspecialchars($valueArg->compileTimeString, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $resultVar->compileTimeDomInnerXml = $inner;
    }

    /**
     * Bind immutable loadHTML getElementById() parse to the result for importNode (#29487 / #20830).
     *
     * @param array<int, Variable> $callArgs
     */
    private function propagateDomGetElementByIdCompileTimeAttrs(Operand $result, array $callArgs): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentGetElementById)) {
            return;
        }
        $idArg = $callArgs[1] ?? null;
        if (!$idArg instanceof Variable) {
            return;
        }
        $idLit = $idArg->compileTimeString
            ?? JIT\JitStringBuiltinArg::compileTimeLiteral($idArg);
        if (null === $idLit || '' === $idLit) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $hit = $this->context->extensionLowering->domCompileTime?->lastGetElementByIdHit();
        if (null === $hit || ($hit['id'] ?? '') !== $idLit) {
            $html = $this->context->extensionLowering->domCompileTime?->lastCompileTimeParsedHtml();
            if (null !== $html) {
                $hit = $this->context->extensionLowering->domCompileTime?->parseIdElementArgv($html, $idLit);
            }
        }
        if (null === $hit || '' === ($hit['id'] ?? '')) {
            return;
        }
        $snapshot = [
            'tag' => (string) ($hit['tag'] ?? 'div'),
            'id' => (string) $hit['id'],
            'text' => (string) ($hit['text'] ?? ''),
        ];
        $resultVar->compileTimeDomGeiHtmlHit = $snapshot;
        $name = JIT\OperandName::resolve($result);
        if (null === $name || '' === $name) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $this->context->namedVariableBindings[$resolved]->compileTimeDomGeiHtmlHit = $snapshot;
        }
    }

    /**
     * Stamp DOMAttr identity on getAttributeNode* / createAttribute* results (#20501).
     *
     * @param array<int, Variable> $callArgs
     */
    private function propagateDomAttrNodeCompileTimeKey(Operand $result, array $callArgs): void
    {
        $toCall = $this->context->scope->toCall;
        $ns = '';
        $local = null;
        if ($toCall instanceof JIT\Call\DomElementGetAttributeNode) {
            $nameArg = $callArgs[1] ?? null;
            if ($nameArg instanceof Variable) {
                $local = $nameArg->compileTimeString;
            }
        } elseif ($toCall instanceof JIT\Call\DomElementGetAttributeNodeNS) {
            $nsArg = $callArgs[1] ?? null;
            $localArg = $callArgs[2] ?? null;
            if ($nsArg instanceof Variable && $localArg instanceof Variable) {
                $ns = $nsArg->isNullConstant ? '' : ($nsArg->compileTimeString ?? '');
                $local = $localArg->compileTimeString;
            }
        } elseif ($toCall instanceof JIT\Call\DomDocumentCreateAttribute) {
            $nameArg = $callArgs[1] ?? null;
            if ($nameArg instanceof Variable) {
                $local = $nameArg->compileTimeString;
            }
        } elseif ($toCall instanceof JIT\Call\DomDocumentCreateAttributeNS) {
            $nsArg = $callArgs[1] ?? null;
            $qArg = $callArgs[2] ?? null;
            if ($nsArg instanceof Variable && $qArg instanceof Variable) {
                $ns = $nsArg->isNullConstant ? '' : ($nsArg->compileTimeString ?? '');
                $q = $qArg->compileTimeString;
                if (null !== $q) {
                    $pos = strpos($q, ':');
                    $local = false === $pos ? $q : substr($q, $pos + 1);
                }
            }
        } else {
            return;
        }
        if (null === $local || '' === $local) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $resultVar->compileTimeDomAttrLocalName = $local;
        $resultVar->compileTimeDomAttrNamespace = $ns;
        $livingDoc = $this->context->extensionLowering->domCompileTime?->lastDocumentClass();
        $resultVar->classUserType = (null !== $livingDoc && str_starts_with($livingDoc, 'Dom\\'))
            ? 'Dom\\Attr'
            : 'DOMAttr';
    }

    /**
     * Mutation helpers that return a child node — copy compile-time DOM metadata onto the
     * result Variable so later cloneNode/saveXML on `$n = $p->appendChild(...)` etc. still
     * see createElement tag/inner/attrs (php-src returns the same node object; #35373 leftover
     * of #35361; insertBefore peer #35377 — null-ref ≡ append still keeps toCall as
     * DomNodeInsertBefore; replaceChild/removeChild peer #35386).
     *
     * Call-arg index of the returned node (php-src ext/dom/node.c):
     * - appendChild / insertBefore / removeChild → `$callArgs[1]` (new/removed child)
     * - replaceChild → `$callArgs[2]` (oldChild — NOT the new child)
     *
     * @param array<int, Variable> $callArgs
     */
    private function propagateDomAppendChildCompileTimeTag(Operand $result, array $callArgs): void
    {
        $toCall = $this->context->scope->toCall;
        $sourceIndex = null;
        if (
            $toCall instanceof JIT\Call\DomNodeAppendChild
            || $toCall instanceof JIT\Call\DomDocumentAppendChild
            || $toCall instanceof JIT\Call\DomNodeInsertBefore
            || $toCall instanceof JIT\Call\DomNodeRemoveChild
        ) {
            $sourceIndex = 1;
        } elseif ($toCall instanceof JIT\Call\DomNodeReplaceChild) {
            // dom_node_replace_child returns the replaced (old) child.
            $sourceIndex = 2;
        } else {
            return;
        }
        $child = $callArgs[$sourceIndex] ?? null;
        if (!$child instanceof Variable) {
            return;
        }
        // ARG_SEND temps for firstChild/item() often drop compileTimeDom* (#32903).
        // Recover lastFetched* before syncing mutation returns so later cloneNode does
        // not fall through to documentElement (#35421 / #35425).
        if (
            $toCall instanceof JIT\Call\DomNodeReplaceChild
            || $toCall instanceof JIT\Call\DomNodeRemoveChild
            || $toCall instanceof JIT\Call\DomNodeAppendChild
            || $toCall instanceof JIT\Call\DomNodeInsertBefore
            || $toCall instanceof JIT\Call\DomDocumentAppendChild
        ) {
            if (null === $child->compileTimeDomTagName || '' === $child->compileTimeDomTagName) {
                $child->compileTimeDomTagName =
                    $this->context->extensionLowering->domCompileTime?->recoveredChildTagName();
            }
            if (null === $child->compileTimeDomChildIndex) {
                $child->compileTimeDomChildIndex =
                    $this->context->extensionLowering->domCompileTime?->recoveredChildIndex();
            }
        }
        // createElement trees (no loadXML): stamp parent inner when LiveMutation did not
        // refresh the receiver Variable (DocumentFragment appendChild #35461). When
        // syncUserScriptInnerXmlFromArgs already concat markup, skip — a second concat
        // duplicated children on cloneNode (#35386 re-open).
        if (
            $toCall instanceof JIT\Call\DomNodeAppendChild
            || $toCall instanceof JIT\Call\DomDocumentAppendChild
            || $toCall instanceof JIT\Call\DomNodeInsertBefore
        ) {
            $parent = $callArgs[0] ?? null;
            if ($parent instanceof Variable) {
                $priorInner = $parent->compileTimeDomInnerXml ?? '';
                // Tag on the parent Variable only — not $lastMaterialized (#35997).
                $isFrag = null !== $this->context->extensionLowering->domCompileTime
                    && $this->context->extensionLowering->domCompileTime->isDocumentFragmentTag(
                        $parent->compileTimeDomTagName ?? null
                    );
                if ($isFrag) {
                    // LiveMutation records lastChildren + sets InnerXml (#35881). Do not
                    // concat here — that doubled fragment markup for importNode.
                } elseif ('' === $priorInner) {
                    $this->appendCompileTimeDomInnerXmlChild($parent, $child);
                }
            }
        }
        // Parent compileTimeDomInnerXml is stamped by JitDomLiveMutationKernel::
        // syncUserScriptInnerXmlFromArgs during appendChild/insertBefore invoke — a second
        // concat here duplicated children on cloneNode (#35386 re-open).
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        // Force-sync present child metadata (result is a fresh box of the same node).
        $this->syncCompileTimeDomTagName($resultVar, $child, true);
        if (null !== $child->classUserType) {
            $resultVar->classUserType = $child->classUserType;
        } elseif (null !== $resultVar->compileTimeDomTagName && '' !== $resultVar->compileTimeDomTagName) {
            // loadXML edge temps may lack classUserType; stamp DOMElement so later
            // cloneNode stays on the direct DomNodeCloneNode path (#35425).
            $resultVar->classUserType = 'DOMElement';
        }
        // replaceChild returns oldChild: a non-empty compileTimeDomAttributes snapshot
        // shadows later setAttribute updates that only refresh CreateElementAttrs /
        // NamedNodeMap pins on the receiver temp, so cloneNode would omit new attrs
        // (#35386). Clear the bag and keep ElementId — clone reads the side-table.
        if ($toCall instanceof JIT\Call\DomNodeReplaceChild) {
            $resultVar->compileTimeDomAttributes = null;
        }
    }

    /**
     * Append one child's outer markup onto the parent's compile-time InnerXml (#35461).
     *
     * Used when there is no loadXML SSOT and LiveMutation left the receiver inner empty
     * (DocumentFragment). Element appendChild already updates inner via
     * {@see JitDomLiveMutationKernel::syncUserScriptInnerXmlFromArgs}.
     */
    private function appendCompileTimeDomInnerXmlChild(Variable $parent, Variable $child): void
    {
        $tag = $child->compileTimeDomTagName;
        if (null === $tag || '' === $tag) {
            return;
        }
        if ('#text' === $tag || '#cdata-section' === $tag) {
            $parent->compileTimeDomInnerXml = ($parent->compileTimeDomInnerXml ?? '')
                .($child->compileTimeDomTextData ?? '');

            return;
        }
        if ('#comment' === $tag) {
            $parent->compileTimeDomInnerXml = ($parent->compileTimeDomInnerXml ?? '')
                .'<!--'.($child->compileTimeDomTextData ?? '').'-->';

            return;
        }
        if (str_starts_with($tag, '#')) {
            return;
        }
        $attrs = '';
        $id = $child->compileTimeDomElementId ?? null;
        $attrMap = null !== $id ? $this->context->extensionLowering->domCompileTime?->createElementAttrsGet($id) : [];
        if (null !== $attrMap && [] !== $attrMap) {
            $attrs = $this->context->extensionLowering->domCompileTime?->formatCreateElementAttrSuffix($attrMap);
        }
        $inner = $child->compileTimeDomInnerXml ?? '';
        $openAttrs = '' === $attrs ? '' : (str_starts_with($attrs, ' ') ? $attrs : ' '.$attrs);
        $markup = '' === $inner
            ? '<'.$tag.$openAttrs.'/>'
            : '<'.$tag.$openAttrs.'>'.$inner.'</'.$tag.'>';
        $parent->compileTimeDomInnerXml = ($parent->compileTimeDomInnerXml ?? '').$markup;
    }

    /** Stamp createDocumentType stand-in tag for Document append/insertBefore (#33584). */
    private function propagateDomCreateDocumentTypeCompileTimeTag(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomImplementationCreateDocumentType)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $this->context->getVariableFromOp($result)->compileTimeDomTagName =
            $this->context->extensionLowering->domCompileTime?->documentTypeTagKind();
    }

    /**
     * Stamp childNodes / getElementsByTagName item(N) compile-time index for thin-AOT (#32903).
     *
     * LiveSlots already refresh held pins (#32784); saveXML still reads PROP_USER_SCRIPT_INNER_XML.
     * Without this index, {@see JitDomReplaceChild} leaves seeded InnerXml
     * unchanged so serialization keeps the replaced sibling.
     *
     * getElementsByTagName()/XPath //tag ->item($N) is the Nth **tag match**, not
     * childNodes[$N]. Using the raw NodeList index as
     * {@see JIT\Variable::$compileTimeDomChildIndex} stamped tag `a` for
     * `getElementsByTagName('b')->item(0)` / `query('//b')->item(0)` and
     * setIdAttribute registered id `x` on `<b>` (or SIGSEGV; #35433 / #35447
     * re-#33957). Prefer {@see $this->context->extensionLowering->domCompileTime?->lastNodeListItemChildIndex()}
     * (mapped in {@see rememberTagListItemChildIndex},
     * #34780).
     *
     * @param array<int, Variable> $callArgs
     */
    private function propagateDomNodeListItemCompileTimeChildIndex(Operand $result, array $callArgs): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomNodeListItem)) {
            return;
        }
        $indexArg = $callArgs[1] ?? null;
        if (!$indexArg instanceof Variable) {
            return;
        }
        // Peer JitDomNodeListItemUserScript / #32831: only stamp when the index is a
        // true LLVM i64 constant — loop `$i` keeps stale compileTimeLong=0 as KIND_VALUE.
        $index = null;
        if (
            null !== $indexArg->value
            && \PHPLLVM\Value::KIND_CONSTANT_INT === $indexArg->value->getKind()
        ) {
            $index = $indexArg->compileTimeLong;
            if (null === $index && null !== $indexArg->compileTimeString && is_numeric($indexArg->compileTimeString)) {
                $index = (int) $indexArg->compileTimeString;
            }
            if (null === $index) {
                $index = (int) $this->context->llvm->lib->LLVMConstIntGetSExtValue($indexArg->value->value);
            }
        }
        if (null === $index || $index < 0) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        // rememberCompileTimeChildIndex already ran in DomNodeListItem::invoke — use its
        // tag-list → direct-child mapping when present (#35433 / #34780).
        $resolvedIndex = $this->context->extensionLowering->domCompileTime?->lastNodeListItemChildIndex();
        $resolvedTag = $this->context->extensionLowering->domCompileTime?->lastNodeListItemTagName();
        if (null !== $resolvedIndex) {
            $resultVar->compileTimeDomChildIndex = $resolvedIndex;
        }
        // Nested getElementsByTagName match: lastFetchedChildIndex is null — do not poison
        // with the NodeList index (that made setIdAttribute read sibling 0's attrs; #35433).
        // Thin AOT materializes NodeList::item elements like firstChild (#32315).
        // Without classUserType, `$list->item(0)->hasAttributeNS(...)` ExternalMethod-nulls
        // even when loadXML seeded the Attr cache (#34618).
        $resultVar->classUserType = 'DOMElement';
        if (null !== $resolvedTag && '' !== $resolvedTag) {
            $resultVar->compileTimeDomTagName = $resolvedTag;
        }
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                $bound->classUserType = 'DOMElement';
                if (null !== $resolvedIndex) {
                    $bound->compileTimeDomChildIndex = $resolvedIndex;
                }
                if (null !== $resolvedTag && '' !== $resolvedTag) {
                    $bound->compileTimeDomTagName = $resolvedTag;
                }
            }
            $this->context->bindVariableByName($resolved, $resultVar);
        }

        $xml = $this->context->extensionLowering->domCompileTime?->lastCompileTimeXml();
        if (
            null === $xml
            || !($this->context->extensionLowering->domCompileTime?->lastLoadWasPureUserScript() ?? false)
        ) {
            return;
        }
        if (null === $resolvedIndex) {
            return;
        }
        $nodes = $this->context->extensionLowering->domCompileTime?->directChildNodesArgv($xml) ?? [];
        if (!isset($nodes[$resolvedIndex]) || 'element' !== ($nodes[$resolvedIndex]['kind'] ?? null)) {
            return;
        }
        $tag = $nodes[$resolvedIndex]['data'] ?? null;
        if (null !== $tag && '' !== $tag && null === $resultVar->compileTimeDomTagName) {
            $resultVar->compileTimeDomTagName = $tag;
        }
        // Open-tag attrs for setIdAttribute / getAttribute on the item() result (#35433).
        $open = $nodes[$resolvedIndex]['open'] ?? null;
        if (null === $open || '' === $open || !\is_string($open)) {
            return;
        }
        $attrs = [];
        foreach ($this->context->extensionLowering->domCompileTime?->attributesFromOpenTagArgv($open) ?? [] as $pair) {
            $attrs[$pair['qname']] = $pair['value'];
            $pos = strpos($pair['qname'], ':');
            if (false !== $pos) {
                $attrs[substr($pair['qname'], $pos + 1)] = $pair['value'];
            }
        }
        if ([] === $attrs) {
            return;
        }
        $resultVar->compileTimeDomAttributes = $attrs;
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $this->context->namedVariableBindings[$resolved]->compileTimeDomAttributes = $attrs;
            }
        }
    }

    /**
     * Remember cloneNode() tag on the result Variable for saveXML of non-root clones.
     */
    private function propagateDomCloneNodeCompileTimeTag(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomNodeCloneNode)) {
            return;
        }
        $tag = $this->context->extensionLowering->domCompileTime?->lastCloneResultTagName();
        if (null === $tag || '' === $tag) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $var->compileTimeDomTagName = $tag;
        $inner = $this->context->extensionLowering->domCompileTime?->lastCloneResultInnerXml();
        if (null !== $inner) {
            $var->compileTimeDomInnerXml = $inner;
        }
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                $bound->compileTimeDomTagName = $tag;
                if (null !== $inner) {
                    $bound->compileTimeDomInnerXml = $inner;
                }
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /** Remember createTextNode('lit') data on the result Variable for splitText (#32362). */
    private function propagateDomCreateTextNodeCompileTimeData(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentCreateTextNode)) {
            return;
        }
        $data = $this->context->extensionLowering->domCompileTime?->lastMaterializedTextData();
        if (null === $data || !$this->context->hasVariableOp($result)) {
            return;
        }
        $this->bindCompileTimeDomLeaf($result, '#text', $data);
    }

    /** Stamp createComment() result for importNode (#35871 leftover of #35098). */
    private function propagateDomCreateCommentCompileTimeData(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentCreateComment)) {
            return;
        }
        $data = $this->context->extensionLowering->domCompileTime?->lastCreateCommentData();
        if (null === $data || !$this->context->hasVariableOp($result)) {
            return;
        }
        $this->bindCompileTimeDomLeaf($result, '#comment', $data);
    }

    /** Stamp createCDATASection() result for importNode (#35871 leftover of #35098). */
    private function propagateDomCreateCDATASectionCompileTimeData(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentCreateCDATASection)) {
            return;
        }
        $data = $this->context->extensionLowering->domCompileTime?->lastCreateCdataData();
        if (null === $data || !$this->context->hasVariableOp($result)) {
            return;
        }
        $this->bindCompileTimeDomLeaf($result, '#cdata-section', $data);
    }

    /** Stamp createProcessingInstruction() result for importNode (#35871 leftover of #35098). */
    private function propagateDomCreateProcessingInstructionCompileTimeData(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentCreateProcessingInstruction)) {
            return;
        }
        $target = $this->context->extensionLowering->domCompileTime?->lastCreatePiTarget();
        if (null === $target || !$this->context->hasVariableOp($result)) {
            return;
        }
        $data = ($this->context->extensionLowering->domCompileTime?->lastCreatePiData() ?? '');
        $this->bindCompileTimeDomLeaf(
            $result,
            $this->context->extensionLowering->domCompileTime?->processingInstructionTagKind(),
            $data,
            ['target' => $target]
        );
    }

    /** Stamp createDocumentFragment() result for importNode (#35871 leftover of #35098). */
    private function propagateDomCreateDocumentFragmentCompileTime(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomDocumentCreateDocumentFragment)) {
            return;
        }
        if (!($this->context->extensionLowering->domCompileTime?->lastCreateDocumentFragmentMaterialized() ?? false)
            || !$this->context->hasVariableOp($result)
        ) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $var->compileTimeDomTagName = $this->context->extensionLowering->domCompileTime?->documentFragmentTagKind();
        $var->compileTimeDomInnerXml = $var->compileTimeDomInnerXml ?? '';
        $var->compileTimeDomNodeListLength = 0;
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                $bound->compileTimeDomTagName = $this->context->extensionLowering->domCompileTime?->documentFragmentTagKind();
                $bound->compileTimeDomInnerXml = $bound->compileTimeDomInnerXml ?? '';
                $bound->compileTimeDomNodeListLength = 0;
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /**
     * @param array<string, string>|null $attrs
     */
    private function bindCompileTimeDomLeaf(
        Operand $result,
        string $tag,
        string $data,
        ?array $attrs = null
    ): void {
        $var = $this->context->getVariableFromOp($result);
        $var->compileTimeDomTagName = $tag;
        $var->compileTimeDomTextData = $data;
        if (null !== $attrs) {
            $var->compileTimeDomAttributes = $attrs;
        }
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                $bound->compileTimeDomTagName = $tag;
                $bound->compileTimeDomTextData = $data;
                if (null !== $attrs) {
                    $bound->compileTimeDomAttributes = $attrs;
                }
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /** Remember splitText() tail data on the result Variable (#32362). */
    private function propagateDomTextSplitTextCompileTimeData(Operand $result): void
    {
        if (!($this->context->scope->toCall instanceof JIT\Call\DomTextSplitText)) {
            return;
        }
        $data = $this->context->extensionLowering->domCompileTime?->lastSplitTextResultData();
        if (null === $data || !$this->context->hasVariableOp($result)) {
            return;
        }
        $this->bindCompileTimeDomTextData($result, $data);
    }

    private function bindCompileTimeDomTextData(Operand $result, string $data): void
    {
        $this->bindCompileTimeDomLeaf($result, '#text', $data);
    }
}
