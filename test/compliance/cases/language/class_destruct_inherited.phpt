--TEST--
Inherited user __destruct() when child object goes out of scope (issue #3144)
--FILE--
<?php
class Base {
    function __destruct() {
        echo "parent\n";
    }
}
class Child extends Base {
}
function f(): void {
    $o = new Child();
}
f();
--EXPECT--
parent
