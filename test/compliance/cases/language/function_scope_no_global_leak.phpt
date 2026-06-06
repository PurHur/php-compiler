--TEST--
Language: function scope must not resolve unbound locals from global scope (#5454)
--FILE--
<?php
$x = 1;
function f(): void {
    var_dump($x);
}
f();
?>
--EXPECT--
PHP Warning:  Undefined variable $x
NULL
