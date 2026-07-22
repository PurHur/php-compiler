<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\Variable;

/**
 * Optional live backing for InternalIterator (php-src zend_create_internal_iterator_zval).
 *
 * Snapshot HashTable remains the default; DOM NodeList uses this so foreach tracks
 * live membership (#21930) while keeping class identity InternalIterator (#21466).
 */
interface InternalIteratorLiveHandler
{
    public function rewind(): void;

    public function next(): void;

    public function valid(): bool;

    public function current(): Variable;

    /**
     * Iteration key — int/string for array-like walks; Variable for object keys (WeakMap; #22267).
     */
    public function key(): int|string|Variable;
}
