<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * stream_select() — delegates to {@see VmStreamSelectPure} (#9216, #12662).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_select)
 */
final class VmStreamSelect
{
    public static function available(): bool
    {
        return VmStreamSelectPure::available();
    }

    /**
     * @param list<StreamSelectPair> $read
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     */
    public static function multiplex(
        array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds,
    ): int|false {
        return VmStreamSelectPure::multiplex($read, $write, $except, $seconds, $microseconds);
    }

    /**
     * @return list<StreamSelectPair>
     */
    public static function pairsFromArray(Variable $arrayVar): array
    {
        $arrayVar = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            return [];
        }
        $pairs = [];
        foreach ($arrayVar->toArray()->iterateKeyed(true) as $pair) {
            [, $streamVar] = $pair;
            $streamVar = $streamVar->resolveIndirect();
            if (!$streamVar->isStreamResource()) {
                continue;
            }
            $handle = ResourceSupport::resolveHandle($streamVar);
            if (null === $handle) {
                continue;
            }
            if (VmPhpFdStream::isValidHandle($handle)) {
                $fd = VmPhpFdStream::fdForHandle($handle);
                if (null === $fd) {
                    continue;
                }
                $pairs[] = new StreamSelectPair($handle, $fd, null);

                continue;
            }
            $host = VmFs::lookupResource($handle);
            if (!\is_resource($host)) {
                continue;
            }
            $pairs[] = new StreamSelectPair($handle, null, $host);
        }

        return $pairs;
    }

    public static function writeBackStreamArray(Variable $targetVar, array $readyHandles, \PHPCompiler\VM\Context $ctx): void
    {
        $targetVar = $targetVar->resolveIndirect();
        $ht = new HashTable();
        $index = 0;
        foreach ($readyHandles as $handle) {
            if (!\is_int($handle)) {
                continue;
            }
            $slot = new Variable();
            $slot->streamHandle($handle, $ctx);
            $ht->addIndex($index, $slot);
            ++$index;
        }
        $replacement = new Variable();
        $replacement->array($ht);
        $targetVar->copyFrom($replacement);
    }
}

/**
 * @internal stream_select pair — VM handle plus native fd or host bootstrap resource.
 */
final class StreamSelectPair
{
    public function __construct(
        public readonly int $handle,
        public readonly ?int $fd,
        public readonly mixed $host,
    ) {
    }
}
