<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomC14NRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::C14N() (#19467, #22378, #32961). */
final class JitDomC14N
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMNode::C14N() expects receiver');
        }
        // Pure loadXML documentElement shadows are not in DomRegistry — NestedJIT
        // DomC14NJitHelper would return "" / corrupt. Replay root outer as C14N (#32961).
        $lit = self::tryCompileTimeDocumentElementC14N($context, $args[1] ?? null);
        if (null !== $lit) {
            return self::boxConstantString($context, $lit);
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
     * Inclusive C14N of the loadXML document element (no comments) for thin-AOT.
     */
    private static function tryCompileTimeDocumentElementC14N(
        Context $context,
        ?JITVariable $exclusiveArg
    ): ?string {
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return null;
        }
        // Only default inclusive C14N (no exclusive / withComments args).
        if (null !== $exclusiveArg) {
            if (null !== $exclusiveArg->compileTimeLong && 0 !== $exclusiveArg->compileTimeLong) {
                return null;
            }
            if (JITVariable::TYPE_NATIVE_BOOL === $exclusiveArg->type) {
                $raw = $context->helper->loadValue($exclusiveArg);
                if (method_exists($raw, 'isConstant') && $raw->isConstant()
                    && method_exists($raw, 'getConstantValue')
                    && 0 !== (int) $raw->getConstantValue()
                ) {
                    return null;
                }
                if (!(method_exists($raw, 'isConstant') && $raw->isConstant())) {
                    return null;
                }
            } elseif (null === $exclusiveArg->compileTimeLong) {
                return null;
            }
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        if ('' === $tag) {
            return null;
        }
        $attrs = '';
        if (preg_match('/<'.preg_quote($tag, '/').'((?:\s[^>]*)?)(\/?)>/', $xml, $m)) {
            $attrs = rtrim($m[1] ?? '', " \t/");
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        // C14N empty elements are start+end tags (php-src xmlC14NDocDumpMemory).
        if ('' === $inner) {
            return '<'.$tag.$attrs.'></'.$tag.'>';
        }

        return '<'.$tag.$attrs.'>'.$inner.'</'.$tag.'>';
    }

    private static function boxConstantString(Context $context, string $lit): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
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
