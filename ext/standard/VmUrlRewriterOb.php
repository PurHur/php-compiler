<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\OutputBuffer;

/**
 * URL-Rewriter output-buffer registration (ext/standard/url.c, #12854, #24370).
 *
 * Flush rewriting lives in {@see UrlScannerEx} / {@see VmUrlRewriterFlush} so NestedJIT of
 * registration helpers stays free of the scanner (#21965 / #24370).
 */
final class VmUrlRewriterOb
{
    public const HANDLER_NAME = 'URL-Rewriter';

    private static bool $registered = false;

    /** Mutable url_rewriter.tags (php-src PHP_INI_ALL default form=). */
    private static string $tags = 'form=';

    /** Mutable url_rewriter.hosts (php-src PHP_INI_ALL default empty). */
    private static string $hosts = '';

    /** Ensure URL-Rewriter ob handler is active (idempotent). */
    public static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }
        foreach (OutputBuffer::getHandlers() as $handler) {
            if (\is_string($handler) && self::HANDLER_NAME === $handler) {
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

    public static function getTags(): string
    {
        return self::$tags;
    }

    public static function setTags(string $tags): void
    {
        self::$tags = $tags;
    }

    public static function getHosts(): string
    {
        return self::$hosts;
    }

    public static function setHosts(string $hosts): void
    {
        self::$hosts = $hosts;
    }
}
