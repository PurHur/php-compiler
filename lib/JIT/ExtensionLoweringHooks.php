<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Module-registered user-script AOT lowering hooks (#36204).
 *
 * {@see \PHPCompiler\JIT} must not import {@code ext\simplexml} / {@code ext\dom} /
 * {@code ext\xmlreader} / {@code ext\xmlwriter} for these paths; Modules register from jitInit.
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
}
