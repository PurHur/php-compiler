--TEST--
Language: exit/die as expression — void type and terminate on evaluate (#3539)
--FILE--
<?php
function f(): void {
    $x = (exit);
}
f();
echo "after\n";
--EXPECT--
