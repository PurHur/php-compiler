<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * DOM compile-time metadata + method-kernel routing owned by ext/dom (#36204).
 *
 * Implemented in {@code ext/dom/JitDomCompileTimeFacade.php}; {@see JIT} must not
 * import {@code ext\dom} for these stamps.
 */
interface DomCompileTimeHooks
{
    public function lastLoadWasPureUserScript(): bool;

    public function lastFetchedTagName(): ?string;

    public function lastMaterializedImportTagName(): ?string;

    public function lastMaterializedTextData(): ?string;

    /** @return array<string, string>|null */
    public function compileTimeAttributesFor(Variable $src, string $tag): ?array;

    public function isDocumentFragmentTag(?string $tag): bool;

    public function nextCreateElementId(string $tag): int;

    /** @return array<string, mixed>|null */
    public function lastGetElementByIdHit(): ?array;

    public function lastCompileTimeParsedHtml(): ?string;

    /** @return array<string, mixed>|null */
    public function parseIdElementArgv(string $html, string $idLit): ?array;

    public function lastDocumentClass(): ?string;

    public function recoveredChildTagName(): ?string;

    public function recoveredChildIndex(): ?int;

    public function lastNodeListItemChildIndex(): ?int;

    public function lastNodeListItemTagName(): ?string;

    /** @return array<string, string> */
    public function createElementAttrsGet(int $id): array;

    /** @param array<string, string> $attrMap */
    public function formatCreateElementAttrSuffix(array $attrMap): string;

    public function documentTypeTagKind(): string;

    public function lastCompileTimeXml(): ?string;

    /**
     * @return list<array<string, mixed>>
     */
    public function directChildNodesArgv(string $xml): array;

    /**
     * @return list<array{qname: string, value: string}>
     */
    public function attributesFromOpenTagArgv(string $open): array;

    public function lastCloneResultTagName(): ?string;

    public function lastCloneResultInnerXml(): ?string;

    public function lastCreateCommentData(): ?string;

    public function lastCreateCdataData(): ?string;

    public function lastCreatePiTarget(): ?string;

    public function lastCreatePiData(): ?string;

    public function processingInstructionTagKind(): string;

    public function lastCreateDocumentFragmentMaterialized(): bool;

    public function documentFragmentTagKind(): string;

    public function lastSplitTextResultData(): ?string;

    public function shouldUseDocumentMethodKernel(Context $context): bool;

    public function tryRouteExcessArgcNonObjectReceiver(
        Context $context,
        string $methodLc,
        Variable $receiverVar,
        Scope $scope
    ): bool;

    public function compileTimeAttrValuePublic(string $ns, string $local): ?string;
}

/**
 * Module-registered user-script AOT lowering hooks (#36204).
 *
 * {@see \PHPCompiler\JIT} must not import {@code ext\simplexml} / {@code ext\dom} /
 * {@code ext\xmlreader} / {@code ext\xmlwriter} / {@code ext\xsl} / {@code ext\mbstring}
 * for these paths; Modules register from jitInit.
 */
final class ExtensionLoweringHooks
{
    /** @var (callable(Context, Variable, Variable): ?Variable)|null */
    public $prepareDimWriteHook = null;

    /** @var (callable(Context, Variable, Variable): ?Value)|null */
    public $offsetGetHook = null;

    /** @var (callable(Context, Variable, Variable): ?Variable)|null */
    public $foldXpathListDimHook = null;

    /** @var (callable(Context, Variable, Variable): ?Value)|null */
    public $propertyGetHook = null;

    /** @var (callable(Variable): bool)|null */
    public $isTrackedSimpleXmlReceiverHook = null;

    /** @var (callable(Variable): void)|null */
    public $applyPendingXpathAssignHook = null;

    /** @var (callable(Variable): bool)|null */
    public $applyPendingElementAssignHook = null;

    /** @var (callable(Variable): bool)|null */
    public $applyPendingIteratorToArrayHostArrayHook = null;

    /** @var (callable(Context, Variable, string, Variable): ?Value)|null */
    public $propertySetHook = null;

    /** @var (callable(Context, Variable, Variable, Variable): ?Value)|null */
    public $offsetSetHook = null;

    /** @var (callable(Context, Variable, Variable): bool)|null */
    public $domTextContentStoreHook = null;

    /** @var (callable(Variable): bool)|null */
    public $applyPendingDomImportAssignHook = null;

    /** @var (callable(?string): void)|null */
    public $setPendingLoadXmlReceiverVarNameHook = null;

    /** @var (callable(): bool)|null */
    public $xmlReaderFactoryIsObjectHook = null;

    /** @var (callable(Variable): void)|null */
    public $bindXmlWriterResultHook = null;

    /** @var (callable(Context, Variable): mixed)|null */
    public $initXmlWriterHook = null;

    /** @var (callable(Context, Variable): mixed)|null */
    public $initXsltHook = null;

    /**
     * @var (callable(Context, \PHPCompiler\Block, array, array, string): ?Value)|null
     */
    public $foldMbNumericEntityHook = null;

    /** @var (callable(Context, Variable, string): ?Value)|null */
    public $foldSimpleXmlPropIssetHook = null;

    /** @var (callable(Context, Variable, Variable): ?Value)|null */
    public $foldSimpleXmlDimIssetHook = null;

    /** @var (callable(Context, Variable, Variable): ?Value)|null */
    public $foldSimpleXmlDimEmptyHook = null;

    /** @var (callable(Context, Variable, ?string): ?Variable)|null */
    public $foldSimpleXmlStringCastHook = null;

    /** @var (callable(Context, ?string): bool)|null */
    public $simpleXmlValueBoxMayBeElementHook = null;

    /** @var (callable(Context, Value): Value)|null */
    public $simpleXmlReadBakedTextHook = null;

    /** @var (callable(Context, Variable, Variable): ?Value)|null */
    public $simpleXmlOffsetUnsetHook = null;

    /** @var (callable(Context, Variable, string): bool)|null */
    public $simpleXmlPropUnsetHook = null;

    /** @var (callable(Variable): ?\SimpleXMLElement)|null */
    public $simpleXmlHostTreeForForeachHook = null;

    /** @var (callable(Context, Variable, \SimpleXMLElement): string)|null */
    public $simpleXmlBindHostTreeForSnapshotHook = null;

    /** DOM compile-time stamps — registered from ext/dom Module::jitInit (#36204). */
    public ?DomCompileTimeHooks $domCompileTime = null;

    public function tryPrepareDimWrite(Context $context, Variable $container, Variable $dim): ?Variable
    {
        return null !== $this->prepareDimWriteHook
            ? ($this->prepareDimWriteHook)($context, $container, $dim)
            : null;
    }

    public function tryOffsetGet(Context $context, Variable $container, Variable $dim): ?Value
    {
        return null !== $this->offsetGetHook
            ? ($this->offsetGetHook)($context, $container, $dim)
            : null;
    }

    public function tryFoldXpathListDim(Context $context, Variable $container, Variable $dim): ?Variable
    {
        return null !== $this->foldXpathListDimHook
            ? ($this->foldXpathListDimHook)($context, $container, $dim)
            : null;
    }

    public function tryPropertyGet(Context $context, Variable $receiver, Variable $name): ?Value
    {
        return null !== $this->propertyGetHook
            ? ($this->propertyGetHook)($context, $receiver, $name)
            : null;
    }

    public function isTrackedSimpleXmlReceiver(Variable $receiver): bool
    {
        return null !== $this->isTrackedSimpleXmlReceiverHook
            && ($this->isTrackedSimpleXmlReceiverHook)($receiver);
    }

    public function applyPendingXpathAssign(Variable $result): void
    {
        if (null !== $this->applyPendingXpathAssignHook) {
            ($this->applyPendingXpathAssignHook)($result);
        }
    }

    public function applyPendingElementAssign(Variable $result): bool
    {
        return null !== $this->applyPendingElementAssignHook
            && ($this->applyPendingElementAssignHook)($result);
    }

    public function applyPendingIteratorToArrayHostArray(Variable $result): bool
    {
        return null !== $this->applyPendingIteratorToArrayHostArrayHook
            && ($this->applyPendingIteratorToArrayHostArrayHook)($result);
    }

    public function tryPropertySet(
        Context $context,
        Variable $container,
        string $propName,
        Variable $value
    ): ?Value {
        return null !== $this->propertySetHook
            ? ($this->propertySetHook)($context, $container, $propName, $value)
            : null;
    }

    public function tryOffsetSet(
        Context $context,
        Variable $receiver,
        Variable $key,
        Variable $value
    ): ?Value {
        return null !== $this->offsetSetHook
            ? ($this->offsetSetHook)($context, $receiver, $key, $value)
            : null;
    }

    public function tryDomTextContentStore(Context $context, Variable $lvalue, Variable $value): bool
    {
        return null !== $this->domTextContentStoreHook
            && ($this->domTextContentStoreHook)($context, $lvalue, $value);
    }

    public function applyPendingDomImportAssign(Variable $result): bool
    {
        return null !== $this->applyPendingDomImportAssignHook
            && ($this->applyPendingDomImportAssignHook)($result);
    }

    public function setPendingLoadXmlReceiverVarName(?string $name): void
    {
        if (null !== $this->setPendingLoadXmlReceiverVarNameHook) {
            ($this->setPendingLoadXmlReceiverVarNameHook)($name);
        }
    }

    public function xmlReaderFactoryIsObject(): bool
    {
        return null !== $this->xmlReaderFactoryIsObjectHook
            && ($this->xmlReaderFactoryIsObjectHook)();
    }

    public function bindXmlWriterResult(Variable $var): void
    {
        if (null !== $this->bindXmlWriterResultHook) {
            ($this->bindXmlWriterResultHook)($var);
        }
    }

    public function tryInitXmlWriter(Context $context, Variable $receiver): mixed
    {
        return null !== $this->initXmlWriterHook
            ? ($this->initXmlWriterHook)($context, $receiver)
            : null;
    }

    public function tryInitXslt(Context $context, Variable $receiver): mixed
    {
        return null !== $this->initXsltHook
            ? ($this->initXsltHook)($context, $receiver)
            : null;
    }

    /**
     * @param list<\PHPCfg\Operand|null> $operands
     * @param Variable[]                 $args
     */
    public function tryFoldMbNumericEntity(
        Context $context,
        \PHPCompiler\Block $block,
        array $operands,
        array $args,
        string $fn
    ): ?Value {
        return null !== $this->foldMbNumericEntityHook
            ? ($this->foldMbNumericEntityHook)($context, $block, $operands, $args, $fn)
            : null;
    }

    public function tryFoldSimpleXmlPropIsset(Context $context, Variable $container, string $propName): ?Value
    {
        return null !== $this->foldSimpleXmlPropIssetHook
            ? ($this->foldSimpleXmlPropIssetHook)($context, $container, $propName)
            : null;
    }

    public function tryFoldSimpleXmlDimIsset(Context $context, Variable $container, Variable $dim): ?Value
    {
        return null !== $this->foldSimpleXmlDimIssetHook
            ? ($this->foldSimpleXmlDimIssetHook)($context, $container, $dim)
            : null;
    }

    public function tryFoldSimpleXmlDimEmpty(Context $context, Variable $container, Variable $dim): ?Value
    {
        return null !== $this->foldSimpleXmlDimEmptyHook
            ? ($this->foldSimpleXmlDimEmptyHook)($context, $container, $dim)
            : null;
    }

    public function tryFoldSimpleXmlStringCast(Context $context, Variable $var, ?string $classHint): ?Variable
    {
        return null !== $this->foldSimpleXmlStringCastHook
            ? ($this->foldSimpleXmlStringCastHook)($context, $var, $classHint)
            : null;
    }

    public function simpleXmlValueBoxMayBeElement(Context $context, ?string $classHint): bool
    {
        return null !== $this->simpleXmlValueBoxMayBeElementHook
            && ($this->simpleXmlValueBoxMayBeElementHook)($context, $classHint);
    }

    public function tryReadSimpleXmlBakedText(Context $context, Value $objPtr): ?Value
    {
        return null !== $this->simpleXmlReadBakedTextHook
            ? ($this->simpleXmlReadBakedTextHook)($context, $objPtr)
            : null;
    }

    public function trySimpleXmlOffsetUnset(Context $context, Variable $container, Variable $dim): ?Value
    {
        return null !== $this->simpleXmlOffsetUnsetHook
            ? ($this->simpleXmlOffsetUnsetHook)($context, $container, $dim)
            : null;
    }

    public function trySimpleXmlPropUnset(Context $context, Variable $container, string $propName): bool
    {
        return null !== $this->simpleXmlPropUnsetHook
            && ($this->simpleXmlPropUnsetHook)($context, $container, $propName);
    }

    public function simpleXmlHostTreeForForeach(Variable $array): ?\SimpleXMLElement
    {
        return null !== $this->simpleXmlHostTreeForForeachHook
            ? ($this->simpleXmlHostTreeForForeachHook)($array)
            : null;
    }

    public function bindSimpleXmlHostTreeForSnapshot(
        Context $context,
        Variable $receiver,
        \SimpleXMLElement $tree
    ): void {
        if (null !== $this->simpleXmlBindHostTreeForSnapshotHook) {
            ($this->simpleXmlBindHostTreeForSnapshotHook)($context, $receiver, $tree);
        }
    }

    public function shouldUseDomDocumentMethodKernel(Context $context): bool
    {
        return null !== $this->domCompileTime
            && $this->domCompileTime->shouldUseDocumentMethodKernel($context);
    }

    public function tryRouteDomExcessArgcNonObjectReceiver(
        Context $context,
        string $methodLc,
        Variable $receiverVar,
        Scope $scope
    ): bool {
        return null !== $this->domCompileTime
            && $this->domCompileTime->tryRouteExcessArgcNonObjectReceiver(
                $context,
                $methodLc,
                $receiverVar,
                $scope
            );
    }
}
