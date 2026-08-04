--TEST--
socket_create()/socket_close() thin AOT (#27394)
--FILE--
<?php
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
var_dump($s !== false);
if ($s) {
    socket_close($s);
}
?>
--EXPECT--
bool(true)
