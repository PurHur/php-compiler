--TEST--
stdlib readline_info() keys (#7059, #15262)
--FILE--
<?php
$info = readline_info();
echo is_array($info) ? "array\n" : "notarray\n";
echo array_key_exists('readline_name', $info) ? "name\n" : "noname\n";
echo array_key_exists('line_buffer', $info) ? "buffer\n" : "nobuffer\n";
echo array_key_exists('point', $info) ? "point\n" : "nopoint\n";
echo array_key_exists('end', $info) ? "end\n" : "noend\n";
echo array_key_exists('library_version', $info) ? "libver\n" : "nolibver\n";
readline_info('readline_name', 'phpc_test');
echo readline_info('readline_name') === 'phpc_test' ? "setget\n" : "badset\n";
readline_info('line_buffer', 'xy');
echo readline_info('end') === 2 ? "endlen\n" : "badend\n";
?>
--EXPECT--
array
name
buffer
point
end
libver
setget
badend
