--TEST--
stdlib serialize() round-trip preserves original class for __PHP_Incomplete_Class (#10765, var.c)
--FILE--
<?php
$obj = unserialize('O:1:"X":0:{}');
echo serialize($obj), "\n";

class Secret {
    public int $secret = 42;
}
$incomplete = unserialize(serialize(new Secret()), ['allowed_classes' => false]);
echo serialize($incomplete), "\n";
echo json_encode($incomplete), "\n";
--EXPECT--
O:1:"X":0:{}
O:6:"Secret":1:{s:6:"secret";i:42;}
{"__PHP_Incomplete_Class_Name":"Secret","secret":42}
