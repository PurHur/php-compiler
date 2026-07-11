<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
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

    public static function lastCompileTimeParsedHtml(): ?string
    {
        return self::$lastCompileTimeHtml;
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
        return DomDocumentMethodUserScriptLlvm::shouldUse($context);
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadHTML() expects receiver and HTML string');
        }

        $parsed = self::tryParseCompileTimeHtml($args[1]);
        if (null === $parsed) {
            $parsed = self::$lastCompileTimeParsed;
        }
        $i1 = $context->getTypeFromString('int1');
        if (null === $parsed) {
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

    /**
     * @param array{tag: string, id: string, text: string} $parsed
     */
    private static function materializeParsedHtml(Context $context, JITVariable $receiver, array $parsed): Value
    {
        $document = self::loadObjectArg($context, $receiver);
        $element = JitDomCreateElement::invoke(
            $context,
            $receiver,
            new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($parsed['tag']))
            )
        );
        self::storeElementTextContent($context, $element, $parsed['text']);
        self::storeElementInIdMap($context, $document, $parsed['id'], $element);
        $idStr = $context->builder->load($context->constantStringFromString($parsed['id']));
        DomUserScriptElementCacheLlvm::store($context, $document, $idStr, $element);
        self::pinUserScriptLoadSideEffects($context);

        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    /** Keep id-map/cache writes when loadHTML() return is discarded (#17954). */
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
