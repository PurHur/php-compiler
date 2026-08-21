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
