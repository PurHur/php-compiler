<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** User-script standalone AOT: pure-LLVM DOMDocument::saveHTML() (#18268). */
final class JitDomSaveHTMLUserScript
{
    private const DOCTYPE = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">';

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function invoke(Context $context): Value
    {
        $parsed = JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        if (null !== $parsed) {
            $body = '<'.$parsed['tag'].'>'.$parsed['text'].'</'.$parsed['tag'].'>';

            return self::boxConstantString($context, self::DOCTYPE."\n<html><body>".$body."</body></html>\n");
        }

        $htmlLit = JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null !== $htmlLit && '' !== trim($htmlLit)) {
            return self::boxConstantString($context, self::DOCTYPE."\n<html><body>".$htmlLit."</body></html>\n");
        }

        return self::boxConstantString($context, self::DOCTYPE."\n<html><body></body></html>\n");
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
