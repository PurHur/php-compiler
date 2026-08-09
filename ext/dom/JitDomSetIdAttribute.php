<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement::setIdAttribute() (#29257).
 *
 * NestedJIT helper updates DomRegistry without PROP_ELEMENT_ID_MAP sync. Thin AOT
 * stores {@see DomUserScriptElementCacheLlvm} using a compile-time id value (from
 * loadXML literal or a preceding setAttribute('id', …)) so getElementById avoids
 * reading an uninitialized id map after loadXML.
 */
final class JitDomSetIdAttribute
{
    /** @var list<string> Compile-time id attribute values from setAttribute('id', …). */
    private static array $setAttributeIdValues = [];

    public static function rememberSetAttributeIdValue(string $value): void
    {
        self::$setAttributeIdValues[] = $value;
    }

    public static function resetCompileTimeState(): void
    {
        self::$setAttributeIdValues = [];
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::setIdAttribute() expects receiver, name, and isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_cont');
        $nameLlvm = JitStringArg::lower($context, $args[1], 'DOMElement::setIdAttribute() name');
        $element = self::loadObjectArg($context, $args[0]);
        $abi = DomSetIdAttributeRuntime::ABI_TRUE;
        $isIdTrue = true;
        if (JITVariable::TYPE_NATIVE_BOOL === $args[2]->type) {
            $raw = $context->helper->loadValue($args[2]);
            if (
                method_exists($raw, 'isConstant')
                && $raw->isConstant()
                && method_exists($raw, 'getConstantValue')
                && (int) $raw->getConstantValue() === 0
            ) {
                $abi = DomSetIdAttributeRuntime::ABI_FALSE;
                $isIdTrue = false;
            }
        }
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureTrueLinked($context);
        } else {
            DomSetIdAttributeRuntime::ensureFalseLinked($context);
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $nameLlvm
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_post');
            $fromSetAttribute = false;
            $idLit = self::resolveCompileTimeIdValue($args[1], $fromSetAttribute);
            if (null !== $idLit && '' !== $idLit) {
                self::storeCacheFirstWins($context, $element, $idLit, $fromSetAttribute);
            }
        }

        return self::boxNull($context);
    }

    private static function resolveCompileTimeIdValue(JITVariable $nameArg, bool &$fromSetAttribute): ?string
    {
        $fromSetAttribute = false;
        $nameLit = JitStringBuiltinArg::compileTimeLiteral($nameArg) ?? $nameArg->compileTimeString;
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        // Prefer last setAttribute($name, $value) — createElement paths (#29257).
        if ([] !== self::$setAttributeIdValues && 'id' === $nameLit) {
            $fromSetAttribute = true;

            return self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1];
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return null;
        }
        // First id="…" / id='…' in the loadXML literal (first-wins matches DomRegistry).
        if (1 === preg_match(
            '/\b'.preg_quote($nameLit, '/').'\s*=\s*(["\'])([^"\']*)\1/',
            $xml,
            $m
        )) {
            return $m[2];
        }

        return null;
    }

    private static function storeCacheFirstWins(
        Context $context,
        Value $element,
        string $idLit,
        bool $overwrite
    ): void {
        $idStr = $context->builder->load($context->constantStringFromString($idLit));
        if ($overwrite) {
            DomUserScriptElementCacheLlvm::store($context, $element, $idStr, $element);

            return;
        }
        $cached = DomUserScriptElementCacheLlvm::lookupObject($context, $idStr);
        $objPtr = $context->getTypeFromString('__object__*');
        $hasHit = $context->builder->icmp(Builder::INT_NE, $cached, $objPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        $storeBlock = $fn->appendBasicBlock('dom_setid_cache_store');
        $cont = $fn->appendBasicBlock('dom_setid_cache_cont');
        // First registration wins — do not replace an existing cache entry for this id.
        $context->builder->branchIf($hasHit, $cont, $storeBlock);

        $context->builder->positionAtEnd($storeBlock);
        DomUserScriptElementCacheLlvm::store($context, $element, $idStr, $element);
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);
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

        throw new \LogicException('DOMElement::setIdAttribute() expects an object receiver');
    }

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
