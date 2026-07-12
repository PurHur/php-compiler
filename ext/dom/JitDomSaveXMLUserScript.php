<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** User-script standalone AOT: pure-LLVM DOMDocument::saveXML() (#18268). */
final class JitDomSaveXMLUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context): ?Value
    {
        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xmlLit || '' === trim($xmlLit)) {
            return null;
        }

        $trimmed = trim($xmlLit);
        $out = str_starts_with($trimmed, '<?xml')
            ? $trimmed."\n"
            : '<?xml version="1.0"?>'."\n".$trimmed."\n";

        return self::boxConstantString($context, $out);
    }

    private static function boxConstantString(Context $context, string $lit): Value
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
}
