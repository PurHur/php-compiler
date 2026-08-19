--TEST--
Language: if() / boolean not / empty() on packed array is zend_is_true (#32475, Zend/zend_operators.c)
--FILE--
<?php
$full = [1, 2];
if ($full) {
    echo "yes\n";
} else {
    echo "no\n";
}
$empty = [];
if ($empty) {
    echo "yes\n";
} else {
    echo "no\n";
}
var_dump(!$full);
var_dump(empty($full));
?>
--EXPECT--
yes
no
bool(false)
bool(false)
