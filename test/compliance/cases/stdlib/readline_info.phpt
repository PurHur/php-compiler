--TEST--
stdlib readline_info() keys (#7059)
--FILE--
<?php
$info = readline_info();
echo is_array($info) ? "array\n" : "notarray\n";
echo array_key_exists('readline_name', $info) ? "name\n" : "noname\n";
echo array_key_exists('line_buffer', $info) ? "buffer\n" : "nobuffer\n";
readline_info('readline_name', 'phpc_test');
echo readline_info('readline_name') === 'phpc_test' ? "setget\n" : "badset\n";
?>
--EXPECT--
array
name
buffer
setget
