<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Variable;

/**
 * Call-result compile-time metadata propagation (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code propagateJsonEncodeFoldedString}
 * through {@code propagateDomImportSimpleXmlCompileTime} (folded json_encode /
 * serialize / str_repeat strings, factory result retags, SimpleXML / unserialize
 * stamps) so JIT.php shrinks for gen-0 split-TU iterability.
 *
 * php-src: ext/json, ext/standard/var.c (serialize), ext/standard/string.c
 * (str_repeat), ext/simplexml, ext/xmlreader, ext/xmlwriter, ext/dom — compile-time
 * meta only; no new C ABI.
 */
trait CallResultCompileTimePropagate
{
    /**
     * Stamp compile-time json_encode() JSON on the result CV so json_decode($j, true) can fold (#24137).
     *
     * @param CoreFunc\Internal|JIT\Call\Native|JIT\Call\ExternalMethod|JIT\Call\NestedClosureInvoke|null $toCall
     */
    private function propagateJsonEncodeFoldedString(Operand $result, $toCall): void
    {
        if (!$toCall instanceof CoreFunc\Internal || 'json_encode' !== strtolower($toCall->getName())) {
            return;
        }
        $folded = $this->context->jitJsonEncodeFoldedString;
        $this->context->jitJsonEncodeFoldedString = null;
        if (null === $folded || !$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $resultVar->compileTimeString = $folded;
        $name = JIT\OperandName::resolve($result);
        if (null === $name || '' === $name) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $this->context->namedVariableBindings[$resolved]->compileTimeString = $folded;
        }
    }

    /**
     * Stamp compile-time serialize() wire on the result CV so unserialize($s) can fold DateTime (#34576).
     *
     * @param CoreFunc\Internal|JIT\Call\Native|JIT\Call\ExternalMethod|JIT\Call\NestedClosureInvoke|null $toCall
     */
    private function propagateSerializeFoldedString(Operand $result, $toCall): void
    {
        if (!$toCall instanceof CoreFunc\Internal || 'serialize' !== strtolower($toCall->getName())) {
            return;
        }
        $folded = $this->context->jitSerializeFoldedString;
        $this->context->jitSerializeFoldedString = null;
        if (null === $folded || !$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $resultVar->compileTimeString = $folded;
        $name = JIT\OperandName::resolve($result);
        if (null === $name || '' === $name) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $this->context->namedVariableBindings[$resolved]->compileTimeString = $folded;
        }
    }

    /**
     * Stamp compile-time str_repeat() on the result CV for json_decode depth+throw fold (#10611).
     *
     * @param CoreFunc\Internal|JIT\Call\Native|JIT\Call\ExternalMethod|JIT\Call\NestedClosureInvoke|null $toCall
     */
    private function propagateStrRepeatFoldedString(Operand $result, $toCall): void
    {
        if (!$toCall instanceof CoreFunc\Internal || 'str_repeat' !== strtolower($toCall->getName())) {
            return;
        }
        $folded = $this->context->jitStrRepeatFoldedString;
        $this->context->jitStrRepeatFoldedString = null;
        if (null === $folded || !$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $resultVar->compileTimeString = $folded;
        $name = JIT\OperandName::resolve($result);
        if (null === $name || '' === $name) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $this->context->namedVariableBindings[$resolved]->compileTimeString = $folded;
        }
    }


    /** Folded BcMath\Number::{add,mul} result metadata for (string) cast / further ops (#26803). */
    private function propagateBcMathNumberMethodCompileTime(Operand $result, mixed $toCall): void
    {
        if (!($toCall instanceof JIT\Call\BcMathNumberMethod)) {
            return;
        }
        $ct = $toCall->lastCompileTimeBcmathNumber;
        if (null === $ct || !$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $var->compileTimeBcmathNumber = $ct;
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $this->context->namedVariableBindings[$resolved]->compileTimeBcmathNumber = $ct;
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /** Thin-AOT DatePeriod::createFromISO8601String() foreach snapshot (#26937). */
    private function propagateDatePeriodCreateFromISO8601CompileTime(Operand $result, mixed $toCall): void
    {
        if (!($toCall instanceof JIT\Call\DatePeriodCreateFromISO8601String)) {
            return;
        }
        $timestamps = $toCall->lastCompileTimeDatePeriodTimestamps;
        if (null === $timestamps || !$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $var->compileTimeDatePeriodTimestamps = $timestamps;
        $var->compileTimeDatePeriodTimezone = $toCall->lastCompileTimeDatePeriodTimezone ?? 'UTC';
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                $bound->compileTimeDatePeriodTimestamps = $timestamps;
                $bound->compileTimeDatePeriodTimezone = $var->compileTimeDatePeriodTimezone;
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /** Attach SimpleXMLElement::xpath() node-set token so `$n[$i]` can fold (#26911). */
    private function propagateSimpleXmlXpathCompileTime(Operand $result, mixed $toCall): void
    {
        if (!($toCall instanceof JIT\Call\SimpleXMLElementXpath)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $this->context->extensionLowering->applyPendingXpathAssign($var);
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])
                && $this->context->namedVariableBindings[$resolved] !== $var
                && null !== $var->compileTimeString
            ) {
                $this->context->namedVariableBindings[$resolved]->compileTimeString = $var->compileTimeString;
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /**
     * XMLReader::XML()/fromString() — InternalArgInfo types XML()/open() as boolean (instance
     * form). Static factory results are objects; retag the CFG operand so `$r->nodeType`
     * does not take the non-object property path (#28670, re-#27299).
     */
    private function propagateXmlReaderFactoryResultType(Operand $result, mixed $toCall): void
    {
        if (
            !($toCall instanceof JIT\Call\XmlReaderXML)
            && !($toCall instanceof JIT\Call\XmlReaderFromString)
            && !($toCall instanceof JIT\Call\XmlReaderFromUri)
            && !($toCall instanceof JIT\Call\XmlReaderFromStream)
            && !($toCall instanceof JIT\Call\XmlReaderOpen)
        ) {
            return;
        }
        // Instance XML()/open() returns bool after resetting $this (#35106 / #35907).
        if (
            (
                $toCall instanceof JIT\Call\XmlReaderXML
                || $toCall instanceof JIT\Call\XmlReaderOpen
            )
            && !$this->context->extensionLowering->xmlReaderFactoryIsObject()
        ) {
            return;
        }
        $result->type = new Type(Type::TYPE_OBJECT, [], 'XMLReader');
        if ($this->context->hasVariableOp($result)) {
            $var = $this->context->getVariableFromOp($result);
            $var->classUserType = 'XMLReader';
            // CFG may have allocated a NATIVE_BOOL slot from InternalArgInfo; keep VALUE.
            if (
                Variable::TYPE_VALUE !== $var->type
                && Variable::TYPE_OBJECT !== $var->type
            ) {
                // Storage already forced in assignCallResultOperand for these callees.
                $var->classUserType = 'XMLReader';
            }
            $name = JIT\OperandName::resolve($result);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    $this->context->namedVariableBindings[$resolved]->classUserType = 'XMLReader';
                }
                $this->context->bindVariableByName($resolved, $var);
            }
        }
    }

    /**
     * XMLWriter::toMemory()/toUri() — static factories return XMLWriter (#19606 leftover of #35872).
     */
    private function propagateXmlWriterFactoryResultType(Operand $result, mixed $toCall): void
    {
        if (
            !($toCall instanceof JIT\Call\XmlWriterToMemory)
            && !($toCall instanceof JIT\Call\XmlWriterToUri)
            && !($toCall instanceof JIT\Call\XmlWriterToStream)
        ) {
            return;
        }
        $result->type = new Type(Type::TYPE_OBJECT, [], 'XMLWriter');
        if ($this->context->hasVariableOp($result)) {
            $var = $this->context->getVariableFromOp($result);
            $var->classUserType = 'XMLWriter';
            $this->context->extensionLowering->bindXmlWriterResult($var);
            $name = JIT\OperandName::resolve($result);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    $this->context->namedVariableBindings[$resolved]->classUserType = 'XMLWriter';
                    $this->context->extensionLowering->bindXmlWriterResult(
                        $this->context->namedVariableBindings[$resolved]
                    );
                }
                $this->context->bindVariableByName($resolved, $var);
            }
        }
    }

    /**
     * Dom\HTMLDocument::createFromString/File — tag the result so saveXml folds
     * the living tree instead of empty LiveSlots {@code <html/>} (leftover of #31324).
     */
    private function propagateDomHtmlDocumentCfsResultType(Operand $result, mixed $toCall): void
    {
        if (
            !($toCall instanceof JIT\Call\DomHtmlDocumentCreateFromString)
            && !($toCall instanceof JIT\Call\DomHtmlDocumentCreateFromFile)
        ) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $var->classUserType = 'Dom\\HTMLDocument';
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $this->context->namedVariableBindings[$resolved]->classUserType = 'Dom\\HTMLDocument';
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /**
     * DOMElement::removeAttributeNode() — InternalArgInfo returns bool until the php-types
     * patch applies; retag so `$removed->name` is not a non-object property fetch (#32707).
     */
    private function propagateDomRemoveAttributeNodeResultType(Operand $result, mixed $toCall): void
    {
        if (!($toCall instanceof JIT\Call\DomElementRemoveAttributeNode)) {
            return;
        }
        $result->type = new Type(Type::TYPE_OBJECT, [], 'DOMAttr');
        if ($this->context->hasVariableOp($result)) {
            $var = $this->context->getVariableFromOp($result);
            $var->classUserType = 'DOMAttr';
            $name = JIT\OperandName::resolve($result);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    $this->context->namedVariableBindings[$resolved]->classUserType = 'DOMAttr';
                }
                $this->context->bindVariableByName($resolved, $var);
            }
        }
    }

    /**
     * dir() — Internal returns object|false; tag Directory so `$d->read()` is not stolen
     * by the XMLReader::read :object shortcut (#30757 / #27299).
     */
    private function propagateDirectoryFactoryResultType(Operand $result, mixed $toCall): void
    {
        if (!($toCall instanceof CoreFunc\Internal)) {
            return;
        }
        if ('dir' !== strtolower($toCall->getName())) {
            return;
        }
        if ($this->context->hasVariableOp($result)) {
            $var = $this->context->getVariableFromOp($result);
            $var->classUserType = 'Directory';
            $name = JIT\OperandName::resolve($result);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    $this->context->namedVariableBindings[$resolved]->classUserType = 'Directory';
                }
                $this->context->bindVariableByName($resolved, $var);
            }
        }
    }

    /**
     * Carry `serialize($obj)` class onto the string temp for unserialize retag (#33876).
     *
     * @param list<Variable> $callArgs
     */
    private function propagateSerializePayloadClass(
        Operand $result,
        mixed $toCall,
        array $callArgs
    ): void {
        if (!($toCall instanceof CoreFunc\Internal)) {
            return;
        }
        if ('serialize' !== strtolower($toCall->getName())) {
            return;
        }
        $src = $callArgs[0] ?? null;
        if (!($src instanceof Variable)) {
            return;
        }
        $class = $src->classUserType;
        if (null === $class || '' === $class) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $var->serializePayloadClass = $class;
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $this->context->namedVariableBindings[$resolved]->serializePayloadClass = $class;
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    /**
     * Tag literal / hinted unserialize(SPL HT-backed | Date*) so foreach / props / methods
     * keep the right class (#33649, #33654, #33876, #34602).
     *
     * Without classUserType, TYPE_VALUE boxes take __value__readHashtable → SEGV, or
     * `$u->y` / `$u->format()` resolve as stdClass / object (#34602 file-backed residual).
     * When the payload class is unknown at compile time, stamp fromUnserializeObject so
     * property/method lowering uses runtime class_id.
     *
     * @param list<Variable> $callArgs
     */
    private function propagateUnserializeSplFixedArrayResultType(
        Operand $result,
        mixed $toCall,
        array $callArgs
    ): void {
        if (!($toCall instanceof CoreFunc\Internal)) {
            return;
        }
        if ('unserialize' !== strtolower($toCall->getName())) {
            return;
        }
        $payload = $callArgs[0] ?? null;
        if (!($payload instanceof Variable)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $class = null;
        $literal = JIT\JitStringArg::compileTimeLiteral($payload);
        if (null !== $literal
            && \preg_match(
                '/^O:\d+:"(SplFixedArray|ArrayObject|ArrayIterator|RecursiveArrayIterator|SplObjectStorage|DateInterval|DateTimeImmutable|DateTime|DateTimeZone)":/',
                $literal,
                $m
            )
        ) {
            $class = $m[1];
        } elseif (null !== $payload->serializePayloadClass && '' !== $payload->serializePayloadClass) {
            $hint = $payload->serializePayloadClass;
            $hintLc = strtolower(ltrim($hint, '\\'));
            if (\in_array(
                $hintLc,
                [
                    'splfixedarray',
                    'arrayobject',
                    'arrayiterator',
                    'recursivearrayiterator',
                    'splobjectstorage',
                    'dateinterval',
                    'datetime',
                    'datetimeimmutable',
                    'datetimezone',
                ],
                true
            )) {
                $class = match ($hintLc) {
                    'splfixedarray' => 'SplFixedArray',
                    'arrayobject' => 'ArrayObject',
                    'arrayiterator' => 'ArrayIterator',
                    'recursivearrayiterator' => 'RecursiveArrayIterator',
                    'splobjectstorage' => 'SplObjectStorage',
                    'dateinterval' => 'DateInterval',
                    'datetime' => 'DateTime',
                    'datetimeimmutable' => 'DateTimeImmutable',
                    'datetimezone' => 'DateTimeZone',
                    default => $hint,
                };
            }
        }
        $var = $this->context->getVariableFromOp($result);
        if (null === $class) {
            // Scalar / array / null wires are not object results — do not force runtime
            // class_id property/method dispatch (#34602).
            if (null !== $literal && !\preg_match('/^O:/', $literal)) {
                return;
            }
            // True runtime O: payload (file_get_contents / function return) — no fold stamp.
            $var->fromUnserializeObject = true;
            $this->context->lastUnserializeObjectClassUserType = null;
            $name = JIT\OperandName::resolve($result);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    $this->context->namedVariableBindings[$resolved]->fromUnserializeObject = true;
                }
                $this->context->bindVariableByName($resolved, $var);
            }

            return;
        }
        $var->classUserType = $class;
        $var->fromUnserializeObject = false;
        $this->context->lastUnserializeObjectClassUserType = $class;
        $result->type = new Type(Type::TYPE_OBJECT, [], $class);
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $this->context->namedVariableBindings[$resolved]->classUserType = $class;
                $this->context->namedVariableBindings[$resolved]->fromUnserializeObject = false;
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }

    private function propagateSimpleXmlElementCompileTime(Operand $result, mixed $toCall): void
    {
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        if (!$this->context->extensionLowering->applyPendingElementAssign($var)) {
            return;
        }
        $this->stampSimpleXmlElementUserType($result, $var);
    }

    private function propagateIteratorToArrayCompileTime(Operand $result, mixed $toCall): void
    {
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        if (!$toCall instanceof CoreFunc\Internal
            || 'iterator_to_array' !== strtolower($toCall->getName())
        ) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        $this->context->extensionLowering->applyPendingIteratorToArrayHostArray($var);
    }

    /**
     * Child views are TYPE_VALUE boxes; without a class stamp FETCH_OBJ skips tryGet /
     * tryPropSet and nested `$sxe->a->b` is empty / a silent write no-op (#35828, #35834).
     */
    private function stampSimpleXmlElementUserType(Operand $result, Variable $var): void
    {
        $var->classUserType = 'SimpleXMLElement';
        $var->magicGetOverloadedClass = 'SimpleXMLElement';
        $result->type = new Type(Type::TYPE_OBJECT, [], 'SimpleXMLElement');
        $name = JIT\OperandName::resolve($result);
        if (null === $name || '' === $name) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])
            && $this->context->namedVariableBindings[$resolved] !== $var
        ) {
            if (null !== $var->compileTimeString) {
                $this->context->namedVariableBindings[$resolved]->compileTimeString = $var->compileTimeString;
            }
            $this->context->namedVariableBindings[$resolved]->classUserType = 'SimpleXMLElement';
            $this->context->namedVariableBindings[$resolved]->magicGetOverloadedClass = 'SimpleXMLElement';
        }
        $this->context->bindVariableByName($resolved, $var);
    }

    private function propagateDomImportSimpleXmlCompileTime(Operand $result, mixed $toCall): void
    {
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $var = $this->context->getVariableFromOp($result);
        if (!$this->context->extensionLowering->applyPendingDomImportAssign($var)) {
            return;
        }
        $var->classUserType = 'DOMElement';
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])
                && $this->context->namedVariableBindings[$resolved] !== $var
            ) {
                $this->context->namedVariableBindings[$resolved]->compileTimeDomAttributes
                    = $var->compileTimeDomAttributes;
                $this->context->namedVariableBindings[$resolved]->compileTimeDomImportHostSxeToken
                    = $var->compileTimeDomImportHostSxeToken;
                $this->context->namedVariableBindings[$resolved]->classUserType = 'DOMElement';
            }
            $this->context->bindVariableByName($resolved, $var);
        }
    }
}
