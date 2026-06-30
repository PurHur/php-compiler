--TEST--
stdlib array_walk() string builtin forwards userdata (ArgumentCountError, #13991)
--FILE--
<?php
$arr = array(1);
try {
    array_walk($arr, 'intval', 'u');
    echo "fail\n";
} catch (ArgumentCountError $e) {
    echo "ok\n";
}
--EXPECT--
ok

