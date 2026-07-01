<?php
/**
 * Issue #14260 — echo concat label prefix dropped after parse_str + get_defined_constants(true).
 * Zend: bool_probe: false   VM (bug): false
 */
declare(strict_types=1);

function bool_probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

parse_str('a=1&b=2', $out);
$c = get_defined_constants(true);
bool_probe('bool_probe', false);
