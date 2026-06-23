--TEST--
stdlib array_fill_keys() JIT — resource keys stringify to Resource id #N (ext/standard/array.c #10847)
--FILE--
<?php
$stream = fopen('php://memory', 'r+');
$r = array_fill_keys([$stream], 1);
$key = 'Resource id #'.(string) get_resource_id($stream);
var_export(isset($r[$key]) ? [$key => $r[$key]] : $r);
echo "\n";
--EXPECT--
array (
  'Resource id #1' => 1,
)
