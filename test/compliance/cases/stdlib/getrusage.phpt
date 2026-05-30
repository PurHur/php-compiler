--TEST--
stdlib getrusage() — resource usage array shape (issue #3240)
--FILE--
<?php
$u = getrusage();
if (!isset($u['ru_maxrss'])) {
    echo "bad_shape\n";
} else {
    echo "shape\n";
}
$c = getrusage(1);
if (!isset($c['ru_maxrss'])) {
    echo "no_children\n";
} else {
    echo "children\n";
}
--EXPECT--
shape
children
