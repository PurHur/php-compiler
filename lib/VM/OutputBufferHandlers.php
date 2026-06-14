<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Pluggable output-buffer flush handlers (ext/standard registers gzip handler; issue #4655).
 *
 * Keeps lib/VM free of ext/ dependencies while allowing ob_start("ob_gzhandler") flush hooks.
 */
final class OutputBufferHandlers
{
    /** @var null|callable(string, string, ?Context): string */
    private static $processor = null;

    /**
     * @param null|callable(string, string, ?Context): string $processor
     */
    public static function register(?callable $processor): void
    {
        self::$processor = $processor;
    }

    public static function apply(string $content, string $handlerName, ?Context $ctx): string
    {
        if (null === self::$processor) {
            return $content;
        }

        return (self::$processor)($content, $handlerName, $ctx);
    }
}
