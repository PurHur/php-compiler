--TEST--
Function-local static without initializer — isset guard (#3533)
--FILE--
<?php
function counter(): int {
    static $n = 0;
    return ++$n;
}
echo counter(), ' ', counter(), "\n";

function ref_static(): void {
    static $x;
    if (!isset($x)) {
        $x = 0;
    }
    $x++;
    echo $x, "\n";
}
ref_static();
ref_static();
--EXPECT--
1 2
1
2
