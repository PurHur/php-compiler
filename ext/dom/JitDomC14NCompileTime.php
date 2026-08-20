<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\CompileTimeDomElementMeta;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time C14N for createElement + setAttribute trees (#32964 / #32973).
 *
 * Thin-AOT LiveSlots elements are not in NestedJIT DomRegistry, so runtime
 * {@see DomC14NJitHelper} cannot reconstruct markup. When the receiver carries
 * createElement metadata, fold via host DOMDocument (peer loadXML C14N fold).
 *
 * Method-call receiver temps often lack the CV's meta pointer; {@see $lastMeta}
 * bridges setAttribute/appendChild/C14NFile onto the createElement bag (peer
 * JitDomSetIdAttribute::$setAttributeIdValues).
 *
 * php-src: ext/dom/node.c zim_dom_node_C14N — disconnected nodes → "" (#19741).
 */
final class JitDomC14NCompileTime
{
    private static ?CompileTimeDomElementMeta $lastMeta = null;

    public static function rememberCreate(CompileTimeDomElementMeta $meta): void
    {
        self::$lastMeta = $meta;
    }

    public static function resetCompileTimeState(): void
    {
        self::$lastMeta = null;
    }

    /** Meta for setAttribute / appendChild — prefer receiver, else last createElement. */
    public static function metaForMutation(JITVariable $receiver): ?CompileTimeDomElementMeta
    {
        if (null !== $receiver->compileTimeDomElementMeta) {
            return $receiver->compileTimeDomElementMeta;
        }
        if (null === self::$lastMeta) {
            return null;
        }
        $receiver->compileTimeDomElementMeta = self::$lastMeta;

        return self::$lastMeta;
    }

    /**
     * @return string|false|null folded payload, false for relative-NS, null if not foldable
     */
    public static function tryFoldCreateElement(JITVariable $receiver, bool $exclusive = false): string|false|null
    {
        $meta = $receiver->compileTimeDomElementMeta ?? self::$lastMeta;
        if (null === $meta) {
            return null;
        }
        $tag = $receiver->compileTimeDomTagName;
        if (null === $tag || '' === $tag) {
            $tag = $meta->tagName;
        }
        if ('' === $tag) {
            return null;
        }
        // Orphans: libxml xmlC14NDocDumpMemory returns "" (#19741).
        if (!$meta->connected) {
            return '';
        }
        if (!class_exists(\DOMDocument::class, false) && !class_exists(\DOMDocument::class)) {
            return null;
        }

        $doc = new \DOMDocument();
        try {
            $el = $doc->createElement($tag);
        } catch (\DOMException $e) {
            unset($e);

            return null;
        }
        foreach ($meta->attributes as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                continue;
            }
            try {
                $el->setAttribute($name, $value);
            } catch (\DOMException $e) {
                unset($e);

                return null;
            }
        }
        $inner = $receiver->compileTimeDomInnerXml;
        if (null === $inner || '' === $inner) {
            $inner = $meta->innerXml;
        }
        if ('' !== $inner) {
            // createElement($name, $value) stores htmlspecialchars text (#32361).
            $text = htmlspecialchars_decode($inner, ENT_QUOTES | ENT_XML1);
            $el->appendChild($doc->createTextNode($text));
        }
        $doc->appendChild($el);
        $payload = @$el->C14N($exclusive, false);
        if (false === $payload) {
            return false;
        }
        if (!\is_string($payload)) {
            return null;
        }

        return $payload;
    }
}
