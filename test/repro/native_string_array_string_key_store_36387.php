<?php

/**
 * Nested list<string> stored under a string key (#36387).
 *
 * phpdoc list<string> lowers to a native `__string__*[]`. Storing that into
 * `$map[$k]` must materialize to a hashtable (peer setAtIndex), not throw
 * "String-key array element type not supported for JIT: __string__*[]".
 *
 * @return array<string, list<string>>
 */
function nest(string $k, string $v): array
{
    $map = [];
    $map[$k] = [$v];

    return $map;
}

$n = nest('k', 'v');
echo $n['k'][0], "\n";
