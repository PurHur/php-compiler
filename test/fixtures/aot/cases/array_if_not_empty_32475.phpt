--TEST--
AOT: if() / ! / empty() on packed native array — zend_is_true IS_ARRAY (#32475 leftover of #32455)
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
--EXPECT--
yes
no
bool(false)
bool(false)
--EXPECT_EXIT--
0
