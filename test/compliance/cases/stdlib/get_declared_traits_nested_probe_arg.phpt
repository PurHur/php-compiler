--TEST--
Regression: get_declared_traits() nested in user-fn probe arg (#14237, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void {
    if (is_bool($result)) {
        echo $label, ': ', $result ? 'true' : 'false', "\n";
        return;
    }
    echo $label . ': ' . json_encode($result) . "\n";
}

probe('declared_traits_has', in_array('Traversable', get_declared_traits(), true));

class CV { public static int $s = 1; }
--EXPECT--
declared_traits_has: false
