--TEST--
User __destruct() when local object goes out of scope (issue #3144)
--FILE--
<?php
class Gone {
    function __destruct() {
        echo "gone\n";
    }
}
function f(): void {
    $o = new Gone();
}
f();
--EXPECT--
gone
