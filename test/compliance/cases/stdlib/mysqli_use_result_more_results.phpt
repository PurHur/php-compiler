--TEST--
ext/mysqli use_result / more_results / stmt multi-result API (#22184)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
$funcs = [
    'mysqli_use_result',
    'mysqli_more_results',
    'mysqli_stmt_more_results',
    'mysqli_stmt_next_result',
    'mysqli_multi_query',
    'mysqli_next_result',
    'mysqli_store_result',
];
foreach ($funcs as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
$mysqli = new ReflectionClass('mysqli');
foreach (['use_result', 'more_results', 'next_result', 'store_result', 'multi_query'] as $m) {
    echo 'mysqli::', $m, ':', $mysqli->hasMethod($m) ? 'yes' : 'no', "\n";
}
$stmt = new ReflectionClass('mysqli_stmt');
foreach (['more_results', 'next_result'] as $m) {
    echo 'mysqli_stmt::', $m, ':', $stmt->hasMethod($m) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
mysqli_use_result:yes
mysqli_more_results:yes
mysqli_stmt_more_results:yes
mysqli_stmt_next_result:yes
mysqli_multi_query:yes
mysqli_next_result:yes
mysqli_store_result:yes
mysqli::use_result:yes
mysqli::more_results:yes
mysqli::next_result:yes
mysqli::store_result:yes
mysqli::multi_query:yes
mysqli_stmt::more_results:yes
mysqli_stmt::next_result:yes
