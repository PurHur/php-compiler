<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: pure-LLVM DOMDocument::saveHTML() (#18268, #24580).
 *
 * loadHTML() fixtures stay compile-time HTML constants. loadXML() literals (including
 * CDATA) are folded through host DOMDocument::saveHTML() so CDATA dumps as text like
 * php-src / libxml htmlNodeDump — without a DomRegistry tree.
 */
final class JitDomSaveHTMLUserScript
{
    private const DOCTYPE = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">';

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    /**
     * @param JITVariable ...$args document [, node]
     */
    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
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

        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $xmlLit && '' !== trim($xmlLit)) {
            $nodeScoped = \count($args) >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1]);
            $html = self::htmlDumpFromXmlLiteral($xmlLit, $nodeScoped);
            if (null !== $html) {
                return self::boxConstantString($context, $html);
            }
        }

        return null;
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $result = self::tryInvoke($context, ...$args);
        if (null !== $result) {
            return $result;
        }

        return self::boxConstantString($context, self::formatSaveHtmlCompileTimeLiteral('', 0));
    }

    /**
     * Fold loadXML literal → HTML via host Zend DOM (php-src htmlNodeDump; #24580).
     *
     * Node-scoped: when the root has a single child, dump that child (matches
     * saveHTML($documentElement->firstChild) for CDATA/text fixtures).
     */
    private static function htmlDumpFromXmlLiteral(string $xml, bool $nodeScoped): ?string
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($xml)) {
                restore_error_handler();

                return null;
            }
            if (!$nodeScoped) {
                $html = $doc->saveHTML();
                restore_error_handler();

                return false === $html ? null : $html;
            }
            $root = $doc->documentElement;
            if (null === $root || !($root instanceof \DOMElement)) {
                restore_error_handler();

                return null;
            }
            if (1 !== $root->childNodes->length) {
                restore_error_handler();

                return null;
            }
            $child = $root->firstChild;
            if (null === $child) {
                restore_error_handler();

                return null;
            }
            $html = $doc->saveHTML($child);
            restore_error_handler();

            return false === $html ? null : $html;
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
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
