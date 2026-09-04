<?php

/**
 * SPINE_CHUNK / partial TU: untyped `$ht[$k]->isInternal = true` when no declared class
 * in the module owns `isInternal` must not abort compile (#36387; regression from #36532).
 *
 * Mirrors ext/ds/BuiltinClasses.php marking ClassEntry::$isInternal without ClassEntry
 * present in the chunk translation unit.
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property (dynamic props on stdClass).
 */

declare(strict_types=1);

final class SpineChunkPropHolder
{
    /** @var array<string, object> */
    public array $items = [];
}

function spine_chunk_mark_internal(SpineChunkPropHolder $h): void
{
    foreach (array_keys($h->items) as $k) {
        $h->items[$k]->isInternal = true;
    }
}

$h = new SpineChunkPropHolder();
$h->items['a'] = new stdClass();
spine_chunk_mark_internal($h);
echo isset($h->items['a']->isInternal) && true === $h->items['a']->isInternal ? "ok\n" : "fail\n";
