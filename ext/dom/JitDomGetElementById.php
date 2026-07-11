<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::getElementById() (#17954).
 *
 * Reads the document's mirrored id map populated by {@see VmDom::syncElementIdMapProperty()}.
 * php-src: ext/dom/php_dom.c — dom_document_get_element_by_id
 */
final class JitDomGetElementById
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        return self::invokeLookup($context, ...$args);
    }

    private static function invokeLookup(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        self::ensureDocumentPropertyLayout($context);

        $document = self::loadObjectArg($context, $args[0]);
        $idStr = self::loadStringArg($context, $args[1]);

        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        $mapVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP,
            $classId
        );
        $ht = HashTableHelper::loadHashtablePointer($context, $mapVar);
        $foundVar = HashTableHelper::readStringKeyToValueBox($context, $ht, $idStr);

        return JitValueBox::valuePtrFromVariable($context, $foundVar);
    }

    private static function ensureDocumentPropertyLayout(Context $context): void
    {
        $object = $context->type->object;
        $classId = $object->lookup(self::CLASS_DOCUMENT);
        if ($object->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            return;
        }
        $object->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_HASHTABLE);
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

        throw new \LogicException('DOMDocument::getElementById() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
