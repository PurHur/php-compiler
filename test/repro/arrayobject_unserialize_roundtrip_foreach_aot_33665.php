<?php
// AOT: foreach after unserialize(serialize(ArrayObject/ArrayIterator)) must not SEGV (#33665)
$ao = unserialize(serialize(new ArrayObject([10, 'x'])));
foreach ($ao as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
$it = unserialize(serialize(new ArrayIterator(['a' => 1])));
foreach ($it as $k => $v) {
    echo $k, '=', var_export($v, true), ';';
}
echo "\n";
