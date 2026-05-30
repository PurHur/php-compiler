--TEST--
Language: throw expression in assignment — JIT path (#3521)
--FILE--
<?php
$assigned = 0;
function f(): int {
    global $assigned;
    $x = throw new Exception('abort');
    $assigned = 1;
    return $x;
}
try {
    f();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    echo $assigned, "\n";
}
?>
--EXPECT--
abort
0
