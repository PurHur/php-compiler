--TEST--
Return-type TypeError getTrace includes violating callable frame (#14369)
--FILE--
<?php
function g(): int {
    return 'x';
}
try {
    g();
} catch (TypeError $e) {
    $t = $e->getTrace();
    echo ($t[0]['function'] ?? '')."\n";
    echo count($t)."\n";
}
?>
--EXPECT--
g
1
