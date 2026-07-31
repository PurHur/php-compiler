--TEST--
ext/mysqli result fetch_all/object/field metadata registration (#22195)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
$funcs = [
    'mysqli_fetch_all',
    'mysqli_fetch_object',
    'mysqli_fetch_field',
    'mysqli_fetch_fields',
    'mysqli_fetch_field_direct',
    'mysqli_fetch_lengths',
    'mysqli_data_seek',
    'mysqli_field_seek',
    'mysqli_field_tell',
    'mysqli_num_fields',
];
foreach ($funcs as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
$methods = [
    'fetch_all',
    'fetch_object',
    'fetch_field',
    'fetch_fields',
    'fetch_field_direct',
    'fetch_lengths',
    'data_seek',
    'field_seek',
    'field_tell',
];
$rc = new ReflectionClass('mysqli_result');
foreach ($methods as $m) {
    echo 'result::', $m, ':', $rc->hasMethod($m) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
mysqli_fetch_all:yes
mysqli_fetch_object:yes
mysqli_fetch_field:yes
mysqli_fetch_fields:yes
mysqli_fetch_field_direct:yes
mysqli_fetch_lengths:yes
mysqli_data_seek:yes
mysqli_field_seek:yes
mysqli_field_tell:yes
mysqli_num_fields:yes
result::fetch_all:yes
result::fetch_object:yes
result::fetch_field:yes
result::fetch_fields:yes
result::fetch_field_direct:yes
result::fetch_lengths:yes
result::data_seek:yes
result::field_seek:yes
result::field_tell:yes
