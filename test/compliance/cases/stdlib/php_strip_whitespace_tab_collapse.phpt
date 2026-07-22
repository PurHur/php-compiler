--TEST--
stdlib php_strip_whitespace() — T_WHITESPACE tab collapses to space (#21951, ext/standard/basic_functions.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-strip-tab-' . getmypid() . '.php';
file_put_contents($path, "<?php\n\techo 1;\n");
echo json_encode(php_strip_whitespace($path)), "\n";
@unlink($path);
--EXPECT--
"<?php\n echo 1; "
