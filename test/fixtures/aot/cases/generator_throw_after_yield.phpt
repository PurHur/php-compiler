--TEST--
AOT: generator throw after yield — foreach / next() catch (#34455)
--FILE--
<?php
function g() {
    yield 1;
    throw new Exception('x');
}
try {
    foreach (g() as $v) {
        echo 'V:', $v, "\n";
    }
    echo "AFTER\n";
} catch (Exception $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
$g = g();
echo 'cur:', $g->current(), "\n";
try {
    $g->next();
    echo "AFTER_NEXT\n";
} catch (Exception $e) {
    echo 'caught_next:', $e->getMessage(), "\n";
}
--EXPECT--
V:1
caught:x
cur:1
caught_next:x
