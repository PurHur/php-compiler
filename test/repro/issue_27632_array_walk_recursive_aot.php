<?php
/**
 * #27632 — thin AOT array_walk_recursive by-ref Closure + null TypeError.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_walk_recursive)
 */
$a = [1, [2]];
array_walk_recursive($a, function (&$v) {
    $v *= 10;
});
echo $a[0].','.$a[1][0], "\n";

$x = null;
try {
    array_walk_recursive($x, function (&$v) {
        $v *= 10;
    });
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
