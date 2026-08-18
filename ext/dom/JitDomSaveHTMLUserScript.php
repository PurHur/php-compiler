<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: pure-LLVM DOMDocument::saveHTML() (#18268, #24580, #25547).
 *
 * loadHTML() / loadXML() literals are folded through host DOMDocument::saveHTML() so
 * document dumps match libxml htmlDocDump (named entities + decimal NCRs) and node
 * dumps match htmlNodeDump (UTF-8) — without a DomRegistry tree.
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
        $nodeScoped = JitDomSaveSerializationArgs::isNodeScoped(
            JitDomSaveSerializationArgs::parse($args)[0]
        );
        $options = JitDomLoadHTMLUserScript::lastCompileTimeOptions() ?? 0;

        // Prefer the original HTML literal so NCRs re-parse through host htmlDocDump (#25547).
        $htmlLit = JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null !== $htmlLit && '' !== trim($htmlLit)) {
            $html = self::htmlDumpFromHtmlLiteral($htmlLit, $options, $nodeScoped);
            if (null !== $html) {
                return self::boxConstantString($context, $html);
            }

            return self::boxConstantString($context, self::formatSaveHtmlCompileTimeLiteral($htmlLit, $options));
        }

        $parsed = JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        if (null !== $parsed) {
            $body = '<'.$parsed['tag'].'>'.$parsed['text'].'</'.$parsed['tag'].'>';
            $html = self::htmlDumpFromHtmlLiteral($body, $options, $nodeScoped);
            if (null !== $html) {
                return self::boxConstantString($context, $html);
            }

            return self::boxConstantString($context, self::formatSaveHtmlCompileTimeLiteral($body, $options));
        }

        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $xmlLit && '' !== trim($xmlLit)) {
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
     * Fold loadHTML literal → HTML via host Zend DOM (php-src htmlDocDump / htmlNodeDump; #25547).
     *
     * Ensures document-wide dumps emit decimal NCRs for unnamed Unicode and honor
     * LIBXML_HTML_NOIMPLIED / LIBXML_HTML_NODEFDTD the same way as VM/JIT.
     */
    private static function htmlDumpFromHtmlLiteral(string $html, int $options, bool $nodeScoped): ?string
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadHTML($html, $options)) {
                restore_error_handler();

                return null;
            }
            if (!$nodeScoped) {
                $out = $doc->saveHTML();
                restore_error_handler();

                return false === $out ? null : $out;
            }
            $root = $doc->documentElement;
            if (null === $root) {
                restore_error_handler();

                return null;
            }
            $out = $doc->saveHTML($root);
            restore_error_handler();

            return false === $out ? null : $out;
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
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
