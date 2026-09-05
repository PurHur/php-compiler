<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Optional live backing for InternalIterator (php-src zend_create_internal_iterator_zval).
 *
 * Snapshot HashTable remains the default; DOM NodeList / WeakMap use this so foreach
 * tracks live membership (#21930, #22267) while keeping class identity InternalIterator (#21466).
 *
 * Lives in lib/ so WeakMap and other core handlers do not import ext\\spl (#36204).
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
