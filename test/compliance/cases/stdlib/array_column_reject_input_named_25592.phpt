--TEST--
stdlib array_column reject named input (#25592, ext/standard/array.stub.php)
--FILE--
<?php
$rows = [['n' => 'a'], ['n' => 'b']];
foreach (['input', 'array', 'column_key'] as $name) {
    try {
        if ($name === 'input' || $name === 'array') {
            $r = array_column(...[$name => $rows, 'column_key' => 'n']);
        } else {
            $r = array_column(...['array' => $rows, $name => 'n']);
        }
        echo "$name=";
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
input=Error: Unknown named parameter $input
array=array (
  0 => 'a',
  1 => 'b',
)
column_key=array (
  0 => 'a',
  1 => 'b',
)
