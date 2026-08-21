<?php
// AOT: foreach after unserialize(serialize(ArrayObject)) must not SEGV (#33665)
$ao = unserialize(serialize(new ArrayObject(['a' => 1, 'b' => 2])));
foreach ($ao as $k => $v) {
    echo $k, '=', $v, ';';
}
echo "\ncount=", $ao->count(), "\n";
