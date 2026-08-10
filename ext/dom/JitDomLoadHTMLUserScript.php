<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: pure-LLVM DOMDocument::loadHTML() (#17954).
 *
 * Avoids VmDom::loadHTML() / ObjectEntry in compiled helper TUs — materializes
 * elements and the document id-map on LLVM objects for JitDomGetElementById.
 *
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class JitDomLoadHTMLUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_TEXT_CONTENT = 'textContent';

    /** @var string|null Last compile-time HTML literal seen during user-script loadHTML lowering */
    private static ?string $lastCompileTimeHtml = null;

    /** @var array{tag: string, id: string, text: string}|null */
    private static ?array $lastCompileTimeParsed = null;

    private static ?int $lastCompileTimeOptions = null;

    public static function lastCompileTimeParsedHtml(): ?string
    {
        return self::$lastCompileTimeHtml;
    }

    public static function lastCompileTimeOptions(): ?int
    {
        return self::$lastCompileTimeOptions;
    }

    /** @var array{tag: string, id: string, text: string}|null Last compile-time getElementById hit (#19212). */
    private static ?array $lastGetElementByIdHit = null;

    public static function rememberLastGetElementByIdHit(array $parsed): void
    {
        self::$lastGetElementByIdHit = $parsed;
    }

    /**
     * @return array{tag: string, id: string, text: string}|null
     */
    public static function lastGetElementByIdHit(): ?array
    {
        return self::$lastGetElementByIdHit;
    }

    /**
     * @return array{tag: string, id: string, text: string}|null
     */
    public static function lastCompileTimeParsed(): ?array
    {
        return self::$lastCompileTimeParsed;
    }

    /** @param array{tag: string, id: string, text: string} $parsed */
    public static function rememberCompileTimeParsed(array $parsed): void
    {
        self::$lastCompileTimeParsed = $parsed;
    }

    public static function shouldUse(Context $context): bool
    {
        return JitDomDocumentMethodKernel::shouldUse($context);
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadHTML() expects receiver and HTML string');
        }

        self::rememberCompileTimeOptions($context, $args[2] ?? null);

        $parsed = self::tryParseCompileTimeHtml($args[1]);
        if (null === $parsed) {
            $parsed = self::$lastCompileTimeParsed;
        }
        $i1 = $context->getTypeFromString('int1');
        if (null === $parsed) {
            if (self::hasCompileTimeFragmentLoad($args[1])) {
                return $i1->constInt(1, false);
            }

            return $i1->constInt(0, false);
        }
        self::$lastCompileTimeParsed = $parsed;

        return self::materializeParsedHtml($context, $args[0], $parsed);
    }

    /**
     * @return array{tag: string, id: string, text: string}|null
     */
    private static function tryParseCompileTimeHtml(JITVariable $htmlArg): ?array
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($htmlArg);
        if (null === $lit) {
            $lit = $htmlArg->compileTimeString;
        }
        if (null !== $lit && '' !== trim($lit)) {
            self::$lastCompileTimeHtml = $lit;
        }
        if (null === $lit || '' === trim($lit)) {
            return null;
        }

        return DomParseSimpleHtmlJitHelper::parseArgv($lit);
    }

    public static function rememberCompileTimeOptions(Context $context, ?JITVariable $optionsArg): void
    {
        if (null === $optionsArg || NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            self::$lastCompileTimeOptions = 0;

            return;
        }
        $resolved = self::tryCompileTimeInt($context, $optionsArg);
        self::$lastCompileTimeOptions = $resolved;
    }

    private static function tryCompileTimeInt(Context $context, JITVariable $optionsArg): ?int
    {
        if (null !== $optionsArg->compileTimeLong) {
            return $optionsArg->compileTimeLong;
        }
        // Bitwise-OR of LIBXML_* constants often lands as LLVM ConstantInt (#25547 AOT).
        $llvmValue = $optionsArg->value ?? null;
        if (null !== $llvmValue && isset($llvmValue->value)) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($llvmValue->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($llvmValue->value);
            }
        }
        $literal = $optionsArg->compileTimeString ?? null;
        if (null !== $literal && is_numeric($literal) && ((string) (int) $literal) === $literal) {
            return (int) $literal;
        }
        $name = $optionsArg->compileTimeConstantName ?? null;
        if (null !== $name) {
            $lookup = strtolower($name);
            if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                return StdlibConstants::CORE_INT_BY_NAME[$lookup];
            }
            foreach (LibxmlConstants::parseFlagConstants() as $constName => $constValue) {
                if (strtolower($constName) === $lookup) {
                    return $constValue;
                }
            }
            $vm = $context->runtime->vmContext;
            if (null !== $vm) {
                $phpVar = $vm->constantFetch($name);
                if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
                    return $phpVar->toInt();
                }
            }
        }

        return null;
    }

    private static function hasCompileTimeFragmentLoad(JITVariable $htmlArg): bool
    {
        $options = self::$lastCompileTimeOptions;
        if (null === $options || 0 === $options) {
            return false;
        }
        $noImplied = 0 !== ($options & LibxmlConstants::LIBXML_HTML_NOIMPLIED);
        $noDefDtd = 0 !== ($options & LibxmlConstants::LIBXML_HTML_NODEFDTD);
        if (!$noImplied || !$noDefDtd) {
            return false;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($htmlArg) ?? $htmlArg->compileTimeString;

        return null !== $lit && '' !== trim($lit);
    }

    /**
     * @param array{tag: string, id: string, text: string} $parsed
     */
    private static function materializeParsedHtml(Context $context, JITVariable $receiver, array $parsed): Value
    {
        // Pure-LLVM nodes: textContent must use property slots, not DomRegistry (#24121 / re-#17954).
        JitDomLoadXMLUserScript::markLastLoadPureUserScript();
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([self::PROP_TEXT_CONTENT, 'nodeValue'] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_STRING);
            }
        }

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
        // Pin html documentElement so DOMDocument::appendChild linkNext sees a real root
        // (TYPE_OBJECT) — setRoot after loadHTML otherwise segfaulted (#29487 / re-#19212).
        self::materializeAndStoreHtmlDocumentElement($context, $document, $element);
        self::pinUserScriptLoadSideEffects($context);

        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    /**
     * libxml htmlReadMemory wraps fragments in {@code <html><body>…</body></html>}.
     * Pin {@code html} as documentElement and attach the id-mapped element under body
     * so appendChild / documentElement fetch match Zend (#29487).
     */
    private static function materializeAndStoreHtmlDocumentElement(
        Context $context,
        Value $document,
        Value $idElement
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_loadhtml_us_document_element');
        $html = JitDomCreateElement::materializeElementFromLiteral($context, 'html');
        $body = JitDomCreateElement::materializeElementFromLiteral($context, 'body');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
            $nodeClassId = $objectType->lookup('DOMNode');
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        $htmlJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $html);
        $bodyJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $body);
        $idJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $idElement);
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);

        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCUMENT_ELEMENT),
            $htmlJit,
            JITVariable::TYPE_OBJECT
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($html, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($body, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $htmlJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($idElement, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $bodyJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($html, self::CLASS_ELEMENT, VmDom::PROP_FIRST_CHILD),
            $bodyJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($html, self::CLASS_ELEMENT, VmDom::PROP_LAST_CHILD),
            $bodyJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($body, self::CLASS_ELEMENT, VmDom::PROP_FIRST_CHILD),
            $idJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($body, self::CLASS_ELEMENT, VmDom::PROP_LAST_CHILD),
            $idJit,
            JITVariable::TYPE_VALUE
        );
        // Document child edges so DOMDocument::appendChild takes linkNext (#29487).
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMNode', VmDom::PROP_FIRST_CHILD),
            $htmlJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMNode', VmDom::PROP_LAST_CHILD),
            $htmlJit,
            JITVariable::TYPE_VALUE
        );
        DomUserScriptPinnedRootLlvm::pin($context, $html);
    }

    /** Keep id-map/cache writes when loadHTML() return is discarded (#17954). */
    private static function pinUserScriptLoadSideEffects(Context $context): void
    {
        foreach (['__phpc_dom_us_ok', '__phpc_dom_us_id', '__phpc_dom_us_elem', '__phpc_dom_us_doc'] as $globalName) {
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
        $object = $context->type->object;
        $classId = $object->lookup(self::CLASS_DOCUMENT);
        if ($object->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            return;
        }
        $object->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
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

        throw new \LogicException('DOMDocument::loadHTML() receiver must be an object');
    }
}
