<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Last HTTP stream-wrapper response headers (php-src ext/standard/basic_functions.c, issue #7236).
 *
 * Populated when {@see VmFs::fileGetContents()} completes an http fetch via {@see VmHttpFetchPure}.
 */
final class VmHttpLastResponseHeaders
{
    private const RESPONSE_HEADER_VAR = 'http_response_header';
    /** @var list<string>|null */
    private static ?array $headers = null;

    public static function isHttpUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * @param list<string>|null $headers
     */
    public static function store(?array $headers): void
    {
        if (null === $headers || [] === $headers) {
            self::$headers = null;

            return;
        }
        self::$headers = $headers;
    }

    /**
     * @return list<string>|null
     */
    public static function get(): ?array
    {
        return self::$headers;
    }

    public static function clear(): void
    {
        self::$headers = null;
    }

    /**
     * Populate caller-scope {@code $http_response_header} after HTTP wrapper I/O (#11839, streams.c).
     *
     * php-src: main/streams/streams.c — php_stream_response_header
     */
    public static function bindResponseHeaderToCaller(Frame $frame): void
    {
        $headers = self::get();
        if (null === $headers) {
            return;
        }
        $caller = $frame->parent;
        if (null === $caller || null === $caller->block) {
            return;
        }

        $ht = new HashTable();
        foreach ($headers as $index => $line) {
            $entry = new Variable();
            $entry->string($line);
            $ht->addIndex($index, $entry);
        }

        $target = $caller->block->ensureVariableByRuntimeName(self::RESPONSE_HEADER_VAR, $caller);
        $arrayVar = new Variable(Variable::TYPE_ARRAY);
        $arrayVar->array($ht);
        $target->copyFrom($arrayVar);

        $slot = $caller->block->slotIndexForVariableName(self::RESPONSE_HEADER_VAR);
        if (null !== $slot) {
            $caller->initializedSlots[$slot] = true;
        }
    }
}
