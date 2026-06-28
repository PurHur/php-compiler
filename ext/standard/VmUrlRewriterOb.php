<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\OutputBuffer;

/**
 * URL-Rewriter output-buffer handler registration (ext/standard/url.c, issue #12854).
 *
 * php-src registers php_output_handler_alias "URL-Rewriter" on first output_add_rewrite_var().
 * ob_list_handlers() / ob_get_status() expose the handler; reset clears vars only.
 */
final class VmUrlRewriterOb
{
    public const HANDLER_NAME = 'URL-Rewriter';

    private static bool $registered = false;

    /** Ensure URL-Rewriter ob handler is active (idempotent). */
    public static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }
        foreach (OutputBuffer::getHandlerNames() as $name) {
            if (self::HANDLER_NAME === $name) {
                self::$registered = true;

                return;
            }
        }
        OutputBuffer::start(self::HANDLER_NAME);
        self::$registered = true;
    }

    /** Request shutdown — OutputBuffer::reset() clears stack; drop local flag. */
    public static function resetState(): void
    {
        self::$registered = false;
    }

    /**
     * Flush hook for URL-Rewriter internal handler (passthrough until full url.c rewrite lands).
     */
    public static function applyHandler(string $content): string
    {
        return $content;
    }
}
