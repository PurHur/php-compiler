<?php
// AOT: foreach after unserialize(serialize(ArrayIterator)) must not SEGV (#33665)
$it = unserialize(serialize(new ArrayIterator(['a' => 1, 'b' => 2])));
foreach ($it as $k => $v) {
    echo $k, '=', $v, ';';
}
echo "\ncount=", $it->count(), "\n";
