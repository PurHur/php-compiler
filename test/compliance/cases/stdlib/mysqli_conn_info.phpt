--TEST--
ext/mysqli connection metadata / ssl_set registration (#22194)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
$funcs = [
    'mysqli_insert_id',
    'mysqli_field_count',
    'mysqli_sqlstate',
    'mysqli_warning_count',
    'mysqli_character_set_name',
    'mysqli_get_charset',
    'mysqli_get_server_info',
    'mysqli_get_host_info',
    'mysqli_get_proto_info',
    'mysqli_get_client_info',
    'mysqli_get_client_version',
    'mysqli_get_server_version',
    'mysqli_ssl_set',
];
foreach ($funcs as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
$methods = [
    'insert_id',
    'field_count',
    'sqlstate',
    'warning_count',
    'character_set_name',
    'get_charset',
    'get_server_info',
    'get_host_info',
    'get_proto_info',
    'get_server_version',
    'get_client_info',
    'ssl_set',
];
$rc = new ReflectionClass('mysqli');
foreach ($methods as $m) {
    echo 'mysqli::', $m, ':', $rc->hasMethod($m) ? 'yes' : 'no', "\n";
}
echo 'client_info:', is_string(mysqli_get_client_info()) ? 'str' : 'bad', "\n";
echo 'client_version:', is_int(mysqli_get_client_version()) ? 'int' : 'bad', "\n";
?>
--EXPECT--
mysqli_insert_id:yes
mysqli_field_count:yes
mysqli_sqlstate:yes
mysqli_warning_count:yes
mysqli_character_set_name:yes
mysqli_get_charset:yes
mysqli_get_server_info:yes
mysqli_get_host_info:yes
mysqli_get_proto_info:yes
mysqli_get_client_info:yes
mysqli_get_client_version:yes
mysqli_get_server_version:yes
mysqli_ssl_set:yes
mysqli::insert_id:yes
mysqli::field_count:yes
mysqli::sqlstate:yes
mysqli::warning_count:yes
mysqli::character_set_name:yes
mysqli::get_charset:yes
mysqli::get_server_info:yes
mysqli::get_host_info:yes
mysqli::get_proto_info:yes
mysqli::get_server_version:yes
mysqli::get_client_info:yes
mysqli::ssl_set:yes
client_info:str
client_version:int
