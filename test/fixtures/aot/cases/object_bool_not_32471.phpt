--TEST--
AOT: !$obj / if($obj) match Zend zend_is_true (#32471 leftover of #32463)
--FILE--
<?php
var_dump(!new stdClass());
$o = new stdClass();
var_dump(!$o);
if ($o) {
    echo "yes\n";
} else {
    echo "no\n";
}
class C32471 {}
var_dump(!new C32471());
--EXPECT--
bool(false)
bool(false)
yes
bool(false)
--EXPECT_EXIT--
0
