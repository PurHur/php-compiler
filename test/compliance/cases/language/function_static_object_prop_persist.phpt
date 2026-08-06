--TEST--
Function-local static object property writes persist across calls (#28040)
--FILE--
<?php
function counter(): int {
    static $x = new stdClass;
    $x->n = ($x->n ?? 0) + 1;
    return $x->n;
}

function sameId(): string {
    static $x = new stdClass;
    return (string) spl_object_id($x);
}

echo counter(), '|', counter(), '|', counter(), '|', counter(), "\n";
$id1 = sameId();
$id2 = sameId();
$id3 = sameId();
echo ($id1 === $id2 && $id2 === $id3) ? "same\n" : "diff\n";
--EXPECT--
1|2|3|4
same
