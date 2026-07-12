--TEST--
ext uuid create random VM parity (issue #5910)
--FILE--
<?php
var_export(defined('UUID_TYPE_RANDOM'));
echo "\n";
var_export(function_exists('uuid_create'));
echo "\n";
$id = uuid_create(UUID_TYPE_RANDOM);
echo strlen($id), "\n";
echo (int) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id), "\n";
$time = uuid_create(UUID_TYPE_TIME);
echo (int) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-1[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $time), "\n";
$out = '';
uuid_generate($out);
echo strlen($out), "\n";
try {
    uuid_create(99);
    echo "bad\n";
} catch (ValueError $e) {
    echo "invalid\n";
}
?>
--EXPECT--
true
true
36
1
1
36
invalid
