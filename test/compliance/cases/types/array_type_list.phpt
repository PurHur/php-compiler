--TEST--
Generic list type — list parameter accepts packed list (#3705)
--FILE--
<?php
function f(list $x): void {
    echo count($x), "\n";
}

f([1, 2]);
echo "ok\n";
--EXPECT--
2
ok
