--TEST--
stdlib array_column() named array:/column_key:/index_key:; reject input: (#25592, #10042)
--FILE--
<?php
var_export(array_column(array: [['x' => 1]], column_key: 'x'));
echo "\n";
try {
    var_export(array_column(input: [['y' => 2]], column_key: 'y'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$rows = [
    ['id' => 1, 'name' => 'a'],
    ['id' => 2, 'name' => 'b'],
];
var_export(array_column(array: $rows, column_key: 'name', index_key: 'id'));
echo "\n";
var_export(array_column($rows, 'id'));
echo "\n";
?>
--EXPECT--
array (
  0 => 1,
)
Error: Unknown named parameter $input
array (
  1 => 'a',
  2 => 'b',
)
array (
  0 => 1,
  1 => 2,
)
