<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Builtin\DomSyncElementIdMapRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Intdiv as JitIntdiv;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::loadHTML() (#17954).
 *
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class JitDomLoadHTML
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadHTML() expects receiver and HTML string');
        }

        $documentId = self::loadDocumentRegistryId($context, $args[0]);
        $htmlStr = self::loadStringArg($context, $args[1]);
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $options = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'DOMDocument::loadHTML()', 2, 'options');
        }

        $loaded = $context->builder->call(
            $context->lookupFunction(DomLoadHTMLRuntime::ABI_NAME),
            $documentId,
            $htmlStr,
            $options
        );

        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            $context->builder->call(
                $context->lookupFunction(DomSyncElementIdMapRuntime::ABI_NAME),
                $documentId
            );
        }

        return $loaded;
    }

    private static function loadDocumentRegistryId(Context $context, JITVariable $receiver): Value
    {
        self::ensureDocumentPropertyLayout($context);
        $document = self::loadObjectArg($context, $receiver);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        $idVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_REGISTRY_ID,
            $classId
        );
        $idPtr = JitValueBox::valuePtrFromVariable($context, $idVar);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $typeByte = $context->builder->load($context->builder->structGep($idPtr, $map['type']));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $nullBlock = BasicBlockHelper::append($context, 'dom_load_html_registry_id_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_load_html_registry_id_read');
        $doneBlock = BasicBlockHelper::append($context, 'dom_load_html_registry_id_done');
        $idPhi = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->branchIf($isNull, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->store($i64->constInt(0, false), $idPhi);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($readBlock);
        $readId = $context->builder->call($context->lookupFunction('__value__readLong'), $idPtr);
        $context->builder->store($readId, $idPhi);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($idPhi);
    }

    private static function ensureDocumentPropertyLayout(Context $context): void
    {
        $object = $context->type->object;
        $classId = $object->lookup(self::CLASS_DOCUMENT);
        if ($object->hasProperty($classId, VmDom::PROP_REGISTRY_ID)) {
            return;
        }
        $object->defineProperty($classId, VmDom::PROP_REGISTRY_ID, JITVariable::TYPE_VALUE);
        if (!$object->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            $object->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
        }
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
