--TEST--
Language: throw expression in assignment — $x = throw ... (#3521, Zend zend_compile.c)
--FILE--
<?php
$assigned = 0;
function f(): int {
    global $assigned;
    $x = throw new LogicException('abort');
    $assigned = 1;
    return $x;
}
try {
    f();
} catch (LogicException $e) {
    echo $e->getMessage(), "\n";
    echo $assigned, "\n";
}
?>
--EXPECT--
abort
0
