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
 * LLVM lowering for DOMNode::C14N() (#19467, #22378, #32961).
 *
 * Thin standalone AOT documentElement temps lose DomRegistry identity — the live
 * helper returns an empty/wrong box and echo prints "Object". When loadXML stamped
 * compileTimeDomNodePath (peer getNodePath #32474), fold C14N via host DOMDocument.
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

        $folded = self::tryCompileTimeFold($args[0], $args[1] ?? null);
        if (null !== $folded) {
            return self::boxStringResult($context, $folded);
        }

        DomC14NRuntime::ensureLinked($context);
        $exclusive = self::exclusiveAsI64($context, $args[1] ?? null);

        // ABI returns __value__* (string or bool false for relative-NS failure; #22378).
        return $context->builder->call(
            $context->lookupFunction(DomC14NRuntime::ABI_NAME),
            self::loadObjectArg($context, $args[0]),
            $exclusive
        );
    }

    /**
     * Fold documentElement / annotated-node C14N from the last pure loadXML (#32961).
     */
    private static function tryCompileTimeFold(JITVariable $receiver, ?JITVariable $exclusiveArg): ?string
    {
        if (null !== $exclusiveArg) {
            // Only fold the default inclusive C14N() — exclusive needs live namespaces.
            if (null !== $exclusiveArg->compileTimeLong && 0 !== $exclusiveArg->compileTimeLong) {
                return null;
            }
            if (JITVariable::TYPE_NATIVE_BOOL === $exclusiveArg->type
                && null === $exclusiveArg->compileTimeLong
            ) {
                // Non-constant exclusive — do not fold.
                return null;
            }
        }

        $path = $receiver->compileTimeDomNodePath;
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $path || null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        if (!class_exists(\DOMDocument::class, false) && !class_exists(\DOMDocument::class)) {
            return null;
        }
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return null;
        }
        $node = self::nodeForCompileTimePath($doc, $path, $receiver->compileTimeDomTagName);
        if (null === $node) {
            return null;
        }
        $canonical = $node->C14N();
        if (!\is_string($canonical)) {
            return null;
        }

        return $canonical;
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
        // Nested paths: walk element children by segment (peer firstChild annotations).
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s));
        if ([] === $segments) {
            return $doc;
        }
        $cur = $el;
        // First segment is the documentElement tag.
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

        // Non-constant exclusive flag: default inclusive (0). Issue repros use literal true/false.
        return $context->context->int64Type()->constInt(0, false);
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
