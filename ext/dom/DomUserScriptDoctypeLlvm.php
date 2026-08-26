<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Compile-time DocumentType stamp for thin-AOT saveXML (#33584).
 *
 * createDocumentType + Document::appendChild does not go through loadXML, so
 * document-wide saveXML must prepend {@code <!DOCTYPE …>} from this side table.
 */
final class DomUserScriptDoctypeLlvm
{
    private static ?string $qualifiedName = null;

    private static string $publicId = '';

    private static string $systemId = '';

    private static bool $attached = false;

    public static function rememberCreate(string $qualifiedName, string $publicId = '', string $systemId = ''): void
    {
        self::$qualifiedName = $qualifiedName;
        self::$publicId = $publicId;
        self::$systemId = $systemId;
        self::$attached = false;
    }

    /**
     * Stamp from a compile-time loadXML literal so document-wide saveXML prepends
     * {@code <!DOCTYPE …>} when the slot walk has no DocumentType child (#34877).
     *
     * createDocumentType+appendChild uses {@see rememberCreate}+{@see markAttached};
     * loadXML materializes only documentElement as firstChild, so without this stamp
     * AOT omits the doctype while Zend/VM keep {@code doc->intSubset}.
     */
    public static function rememberAttachedFromLoadXml(string $xml): void
    {
        self::reset();
        $trimmed = trim($xml);
        // Match name + optional PUBLIC "pub" "sys" | PUBLIC "pub" | SYSTEM "sys" (+ optional internal subset).
        // Name token only — do not use \\S+ (greedy through the document's final '>').
        if (1 !== preg_match(
            '/<!DOCTYPE\s+([a-zA-Z_][\w:.-]*)(?:\s+PUBLIC\s+"([^"]*)"\s+"([^"]*)"|\s+PUBLIC\s+"([^"]*)"|\s+SYSTEM\s+"([^"]*)")?\s*(?:\[[^\]]*\])?\s*>/i',
            $trimmed,
            $m
        )) {
            return;
        }
        $name = $m[1];
        $publicId = '' !== ($m[2] ?? '') ? (string) $m[2] : (string) ($m[4] ?? '');
        $systemId = '' !== ($m[3] ?? '') ? (string) $m[3] : (string) ($m[5] ?? '');
        self::rememberCreate($name, $publicId, $systemId);
        self::markAttached();
    }

    public static function markAttached(): void
    {
        if (null !== self::$qualifiedName) {
            self::$attached = true;
        }
    }

    public static function reset(): void
    {
        self::$qualifiedName = null;
        self::$publicId = '';
        self::$systemId = '';
        self::$attached = false;
    }

    /** Leading markup including trailing newline, or empty when no attached doctype. */
    public static function saveXmlPrefix(): string
    {
        if (!self::$attached || null === self::$qualifiedName || '' === self::$qualifiedName) {
            return '';
        }
        $name = self::$qualifiedName;
        $pub = self::$publicId;
        $sys = self::$systemId;
        if ('' !== $pub && '' !== $sys) {
            return '<!DOCTYPE '.$name.' PUBLIC "'.$pub.'" "'.$sys.'">'."\n";
        }
        if ('' !== $pub) {
            return '<!DOCTYPE '.$name.' PUBLIC "'.$pub.'">'."\n";
        }
        if ('' !== $sys) {
            return '<!DOCTYPE '.$name.' SYSTEM "'.$sys.'">'."\n";
        }

        return '<!DOCTYPE '.$name.'>'."\n";
    }
}
