<?php
/**
 * Issue #14800 — probe label prefix dropped after get_defined_constants(true) alone.
 * Zend: declared_traits_has: false   VM (bug): false
 */
declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

$c = get_defined_constants(true);
probe('declared_traits_has', in_array('Traversable', get_declared_traits(), true));
