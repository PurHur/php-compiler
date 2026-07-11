<?php

declare(strict_types=1);

/**
 * Issue #17993 — str_contains()/str_starts_with()/str_ends_with() Stringable object (#16925 regression).
 *
 * @see Zend/php-src ext/standard/string.c php_str_contains(), php_str_starts_with(), php_str_ends_with()
 */

class C
{
    public function __toString(): string
    {
        return 'obj';
    }
}

foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(new C(), 'obj');
        echo "{$fn}=uncaught\n";
    } catch (TypeError $e) {
        echo "{$fn}=TypeError\n";
    }
}
