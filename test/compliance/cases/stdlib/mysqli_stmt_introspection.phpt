--TEST--
ext/mysqli stmt introspection registration (#22193)
--FILE--
<?php
$funcs = [
    'mysqli_stmt_field_count',
    'mysqli_stmt_param_count',
    'mysqli_stmt_sqlstate',
    'mysqli_stmt_errno',
    'mysqli_stmt_error',
    'mysqli_stmt_insert_id',
    'mysqli_stmt_num_rows',
    'mysqli_stmt_affected_rows',
    'mysqli_stmt_data_seek',
    'mysqli_stmt_reset',
    'mysqli_stmt_store_result',
    'mysqli_stmt_get_result',
    'mysqli_stmt_free_result',
    'mysqli_stmt_result_metadata',
];
foreach ($funcs as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
$methods = [
    'field_count',
    'param_count',
    'sqlstate',
    'errno',
    'error',
    'insert_id',
    'num_rows',
    'affected_rows',
    'data_seek',
    'reset',
    'store_result',
    'get_result',
    'free_result',
    'result_metadata',
];
$rc = new ReflectionClass('mysqli_stmt');
foreach ($methods as $m) {
    echo 'stmt::', $m, ':', $rc->hasMethod($m) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
mysqli_stmt_field_count:yes
mysqli_stmt_param_count:yes
mysqli_stmt_sqlstate:yes
mysqli_stmt_errno:yes
mysqli_stmt_error:yes
mysqli_stmt_insert_id:yes
mysqli_stmt_num_rows:yes
mysqli_stmt_affected_rows:yes
mysqli_stmt_data_seek:yes
mysqli_stmt_reset:yes
mysqli_stmt_store_result:yes
mysqli_stmt_get_result:yes
mysqli_stmt_free_result:yes
mysqli_stmt_result_metadata:yes
stmt::field_count:yes
stmt::param_count:yes
stmt::sqlstate:yes
stmt::errno:yes
stmt::error:yes
stmt::insert_id:yes
stmt::num_rows:yes
stmt::affected_rows:yes
stmt::data_seek:yes
stmt::reset:yes
stmt::store_result:yes
stmt::get_result:yes
stmt::free_result:yes
stmt::result_metadata:yes
