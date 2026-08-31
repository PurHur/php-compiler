<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomC14NRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::C14N() (#19467, #22378, #32961, #32962).
 *
 * Thin standalone AOT documentElement temps lose DomRegistry identity — the live
 * helper returned a Variable object box and echo printed "Object". Fold pure loadXML
 * C14N via host DOMDocument (peer getNodePath #32474):
 * - annotated compileTimeDomNodePath (#32961)
 * - DOMDocument receiver / documentElement without path (#32962)
 * Relative-NS failure stays Zend `false` via boxBoolFalse / ?string null bridge.
 */
final class JitDomC14N
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14n_invoke_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14N',
            0,
            4
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if ([] === $args) {
            throw new \LogicException('DOMNode::C14N() expects receiver');
        }

        $exclusiveArg = $args[1] ?? null;
        if (self::exclusivePreventsFold($exclusiveArg)) {
            $folded = null;
        } else {
            $folded = self::tryFoldCompileTime($args[0]);
        }
        if (null !== $folded) {
            if (false === $folded) {
                return self::boxBoolFalse($context);
            }

            return self::boxStringResult($context, $folded);
        }

        DomC14NRuntime::ensureLinked($context);
        $exclusive = self::exclusiveAsI64($context, $exclusiveArg);

        // ABI returns __value__* (string or bool false; #22378 / #32962).
        return $context->builder->call(
            $context->lookupFunction(DomC14NRuntime::ABI_NAME),
            self::loadObjectArg($context, $args[0]),
            $exclusive
        );
    }

    /**
     * Public fold for {@see JitDomC14NFile} (#32964 / #32973).
     *
     * @return string|false|null
     */
    public static function tryFoldCanonical(JITVariable $receiver, ?JITVariable $exclusiveArg = null): string|false|null
    {
        if (self::exclusivePreventsFold($exclusiveArg)) {
            return null;
        }

        return self::tryFoldCompileTime($receiver);
    }

    /**
     * @return string|false|null folded payload, false for relative-NS, null if not foldable
     */
    private static function tryFoldCompileTime(JITVariable $receiver): string|false|null
    {
        $created = self::tryFoldCreateElement($receiver);
        if (null !== $created) {
            return $created;
        }

        $xml = JitDomLoadXMLUserScript::compileTimeXmlFor($receiver)
            ?? $receiver->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::unambiguousCompileTimeXml();
        $xml = self::compileTimeXmlWithReceiverInner($receiver, $xml);
        if (null === $xml
            || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            || JitDomLoadXMLUserScript::treeMutatedSinceLoad()
        ) {
            return null;
        }
        if (!class_exists(\DOMDocument::class, false) && !class_exists(\DOMDocument::class)) {
            return null;
        }

        $path = $receiver->compileTimeDomNodePath;
        if (null !== $path && '' !== $path) {
            return self::foldAnnotatedPath($xml, $path, $receiver);
        }

        $class = strtolower(str_replace('/', '\\', ltrim((string) $receiver->classUserType, '\\')));
        $isDocument = '' !== $class
            && str_contains($class, 'document')
            && !str_contains($class, 'element');
        if ($isDocument) {
            return self::foldHostC14N($xml, null);
        }

        // documentElement / :object temps after loadXML — root element (#32962).
        return self::foldHostC14N($xml, $receiver);
    }

    /**
     * createElement('lit') [+ setAttribute lit] without loadXML (#32973).
     *
     * @return string|false|null
     */
    private static function tryFoldCreateElement(JITVariable $receiver): string|false|null
    {
        // Prefer an explicit createElement id. lastId() is only for ARG_SEND copies that
        // dropped stamps (#32973) — never for loadXML documentElement temps (#32978), or a
        // later document's C14N folds into the previous createElement tag.
        $id = $receiver->compileTimeDomElementId;
        if (null === $id
            && null === $receiver->compileTimeDomNodePath
            && null === $receiver->compileTimeDomLoadXml
            && null === JitDomLoadXMLUserScript::compileTimeXmlFor($receiver)
        ) {
            $id = JitDomCreateElementAttrs::lastId();
        }
        $tag = $receiver->compileTimeDomTagName;
        if ((null === $tag || '' === $tag) && null !== $id) {
            $tag = JitDomCreateElementAttrs::tag($id);
        }
        if (null === $tag || '' === $tag) {
            return null;
        }
        // Prefer loadXML annotation when present — createElement tag alone is not the root.
        if (null !== $receiver->compileTimeDomNodePath && '' !== $receiver->compileTimeDomNodePath) {
            return null;
        }
        if (null !== JitDomLoadXMLUserScript::compileTimeXmlFor($receiver)) {
            return null;
        }
        if (!class_exists(\DOMDocument::class, false) && !class_exists(\DOMDocument::class)) {
            return null;
        }
        $doc = new \DOMDocument();
        $el = @$doc->createElement($tag);
        if (!$el instanceof \DOMElement) {
            return null;
        }
        foreach ($receiver->compileTimeDomAttributes ?? [] as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                continue;
            }
            @$el->setAttribute($name, $value);
        }
        if (null !== $id) {
            foreach (JitDomCreateElementAttrs::get($id) as $name => $value) {
                @$el->setAttribute($name, $value);
            }
        }
        $inner = $receiver->compileTimeDomInnerXml;
        if (null !== $inner && '' !== $inner) {
            // Text-only createElement($name, $value) — peer htmlspecialchars seed (#32361).
            $el->appendChild($doc->createTextNode(html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8')));
        }
        // libxml C14N of an unattached element is empty — attach first (Zend/php-src).
        @$doc->appendChild($el);
        $payload = @$el->C14N();
        if (false === $payload) {
            return false;
        }
        if (!\is_string($payload) || '' === $payload) {
            return null;
        }

        return $payload;
    }

    /**
     * @return string|false|null
     */
    private static function foldAnnotatedPath(string $xml, string $path, JITVariable $receiver): string|false|null
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return null;
        }
        $node = self::nodeForCompileTimePath($doc, $path, $receiver->compileTimeDomTagName);
        if (null === $node) {
            return null;
        }
        $canonical = @$node->C14N();
        if (false === $canonical) {
            return false;
        }
        if (!\is_string($canonical)) {
            return null;
        }

        return $canonical;
    }

    /**
     * @return string|false|null
     */
    private static function foldHostC14N(string $xml, ?JITVariable $elementReceiver): string|false|null
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return null;
        }
        if (null === $elementReceiver) {
            $payload = @$doc->C14N();
        } else {
            $el = $doc->documentElement;
            if (null === $el) {
                return null;
            }
            if (null !== $elementReceiver->compileTimeDomTagName && '' !== $elementReceiver->compileTimeDomTagName) {
                $list = $doc->getElementsByTagName($elementReceiver->compileTimeDomTagName);
                $idx = $elementReceiver->compileTimeDomChildIndex ?? 0;
                $candidate = $list->item((int) $idx);
                if ($candidate instanceof \DOMElement) {
                    $el = $candidate;
                }
            }
            $payload = @$el->C14N();
        }
        if (false === $payload) {
            return false;
        }

        return (string) $payload;
    }

    private static function nodeForCompileTimePath(
        \DOMDocument $doc,
        string $path,
        ?string $tagHint
    ): ?\DOMNode {
        if ('/' === $path) {
            return $doc;
        }
        $el = $doc->documentElement;
        if (null === $el) {
            return null;
        }
        $rootPath = '/'.$el->nodeName;
        if ($path === $rootPath || (null !== $tagHint && $path === '/'.$tagHint)) {
            return $el;
        }
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s));
        if ([] === $segments) {
            return $doc;
        }
        $cur = $el;
        array_shift($segments);
        foreach ($segments as $segment) {
            $found = null;
            for ($child = $cur->firstChild; null !== $child; $child = $child->nextSibling) {
                if (XML_ELEMENT_NODE !== $child->nodeType) {
                    continue;
                }
                if ($child->nodeName === $segment) {
                    $found = $child;
                    break;
                }
            }
            if (null === $found) {
                return null;
            }
            $cur = $found;
        }

        return $cur;
    }

    /**
     * Rebuild loadXML literal from stamped inner after appendChild mutations (#32972 / #34862).
     *
     * {@see foldAnnotatedPath} reads the full document literal; LiveSlots refresh may
     * update {@see JITVariable::$compileTimeDomInnerXml} without rewriting loadXml first.
     */
    private static function compileTimeXmlWithReceiverInner(JITVariable $receiver, ?string $xml): ?string
    {
        if (null === $xml || '' === trim($xml)) {
            return $xml;
        }
        $inner = $receiver->compileTimeDomInnerXml ?? null;
        if (null === $inner || '' === $inner) {
            return $xml;
        }
        $currentInner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        if ($inner === $currentInner) {
            return $xml;
        }
        $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($xml);
        if (null === $parsed) {
            return $xml;
        }

        return '<'.$parsed['tag'].$parsed['attrs'].'>'.$inner.'</'.$parsed['tag'].'>';
    }

    /** True when exclusive is non-default / non-constant — skip inclusive loadXML fold. */
    private static function exclusivePreventsFold(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        // Non-constant exclusive flag — exclusive C14N needs live namespaces (#32961).
        return true;
    }

    private static function exclusiveAsI64(Context $context, ?JITVariable $arg): Value
    {
        if (null === $arg) {
            return $context->context->int64Type()->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $raw = $context->helper->loadValue($arg);
            if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                return $context->context->int64Type()->constInt(
                    ((int) $raw->getConstantValue() !== 0) ? 1 : 0,
                    false
                );
            }

            return $context->builder->zExt($raw, $context->context->int64Type());
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return $context->context->int64Type()->constInt(0 !== $arg->compileTimeLong ? 1 : 0, false);
        }

        return $context->context->int64Type()->constInt(0, false);
    }

    private static function boxStringResult(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function boxBoolFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $context->getTypeFromString('int32')->constInt(0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
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

        throw new \LogicException('DOMNode::C14N() receiver must be an object');
    }
}
