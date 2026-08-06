<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\OutputBuffer;

/**
 * URL-Rewriter output-buffer registration (ext/standard/url.c, #12854, #24370).
 *
 * Tags/hosts storage SSOT: {@see OutputRewriteVarsJitHelper} so AOT Ini + flush share
 * one NestedJIT static (#27566). Flush rewriting lives in {@see UrlScannerEx} /
 * {@see VmUrlRewriterFlush} so NestedJIT of registration helpers stays free of the
 * scanner (#21965 / #24370).
 */
final class VmUrlRewriterOb
{
    public const HANDLER_NAME = 'URL-Rewriter';

    private static bool $registered = false;

    /** Ensure URL-Rewriter ob handler is active (idempotent) — VM OutputBuffer only. */
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
        return OutputRewriteVarsJitHelper::getTags();
    }

    public static function setTags(string $tags): void
    {
        OutputRewriteVarsJitHelper::setTags($tags);
    }

    public static function getHosts(): string
    {
        return OutputRewriteVarsJitHelper::getHosts();
    }

    public static function setHosts(string $hosts): void
    {
        OutputRewriteVarsJitHelper::setHosts($hosts);
    }
}
