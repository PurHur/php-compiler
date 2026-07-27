--TEST--
AOT: single concat expression as call arg must survive (#23798)
--FILE--
<?php
function f($a) {
    echo $a, "\n";
}
$s = 's';
f($s . '1');
--EXPECT--
s1
