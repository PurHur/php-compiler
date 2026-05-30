--TEST--
Language: __METHOD__ in function scope equals function name (#3595)
--FILE--
<?php
function f() {
    echo __FUNCTION__, "\n", __METHOD__, "\n";
}
f();
--EXPECT--
f
f
