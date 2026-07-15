<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
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
        $options = JitDomLoadHTMLUserScript::lastCompileTimeOptions() ?? 0;
        if (null !== $parsed) {
            $body = '<'.$parsed['tag'].'>'.$parsed['text'].'</'.$parsed['tag'].'>';

            return self::boxConstantString($context, self::formatSaveHtmlCompileTimeLiteral($body, $options));
        }

        $htmlLit = JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null !== $htmlLit && '' !== trim($htmlLit)) {
            return self::boxConstantString($context, self::formatSaveHtmlCompileTimeLiteral($htmlLit, $options));
        }

        return self::boxConstantString($context, self::formatSaveHtmlCompileTimeLiteral('', $options));
    }

    private static function formatSaveHtmlCompileTimeLiteral(string $htmlLit, int $options): string
    {
        $trimmed = trim($htmlLit);
        $noDefDtd = 0 !== ($options & LibxmlConstants::LIBXML_HTML_NODEFDTD);
        $noImplied = 0 !== ($options & LibxmlConstants::LIBXML_HTML_NOIMPLIED);

        if ($noImplied && $noDefDtd) {
            return ('' === $trimmed ? '' : $trimmed)."\n";
        }
        if ($noImplied) {
            return self::DOCTYPE."\n".('' === $trimmed ? '' : $trimmed)."\n";
        }
        if ($noDefDtd) {
            return '<html><body>'.$trimmed."</body></html>\n";
        }

        return self::DOCTYPE."\n<html><body>".$trimmed."</body></html>\n";
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
