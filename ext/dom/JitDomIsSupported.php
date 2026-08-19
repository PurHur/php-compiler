<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMNode::isSupported() / DOMImplementation::hasFeature()
 * (php-src dom_has_feature).
 *
 * Thin standalone AOT documentElement temps lose DOMElement userType and
 * NestedJIT DomRegistry is empty — instance-invoke aborts. php-src does not
 * read the node; fold compile-time feature/version via {@see VmDom::hasFeature}.
 *
 * DOMImplementation::hasFeature is the same C helper; without a Call proxy
 * user-script AOT binds an unbound method and prints false (#32491).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, isSupported)
 *          ext/dom/implementation.c PHP_METHOD(DOMImplementation, hasFeature)
 *          ext/dom/php_dom.c dom_has_feature (#32480, #32491)
 */
final class JitDomIsSupported
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return self::invokeFeatureProbe($context, 'DOMNode::isSupported', ...$args);
    }

    public static function invokeHasFeature(Context $context, JITVariable ...$args): Value
    {
        return self::invokeFeatureProbe($context, 'DOMImplementation::hasFeature', ...$args);
    }

    private static function invokeFeatureProbe(
        Context $context,
        string $methodName,
        JITVariable ...$args
    ): Value {
        $contTag = 'DOMImplementation::hasFeature' === $methodName
            ? 'dom_hasfeature_cont'
            : 'dom_issupported_cont';
        BasicBlockHelper::ensureOpenInsertBlock($context, $contTag);
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            $methodName,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $featureArg = $args[1];
        $versionArg = $args[2];
        $feature = JitStringBuiltinArg::compileTimeLiteral($featureArg)
            ?? $featureArg->compileTimeString;
        $version = JitStringBuiltinArg::compileTimeLiteral($versionArg)
            ?? $versionArg->compileTimeString;
        if (null === $feature || null === $version) {
            throw new \LogicException(
                $methodName.'() user-script AOT requires compile-time feature/version'
            );
        }

        return self::boxBoolResult($context, VmDom::hasFeature($feature, $version));
    }

    private static function boxBoolResult(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
