<?php
// Repro #25592: Zend rejects named input; array/column_key stay valid.
$rows = [['n' => 'a'], ['n' => 'b']];
$ok = true;
try {
    array_column(input: $rows, column_key: 'n');
    echo "FAIL: input accepted\n";
    $ok = false;
} catch (Error $e) {
    echo $e->getMessage() === 'Unknown named parameter $input' ? "input:OK\n" : ('input:'.$e->getMessage()."\n");
}
$r = array_column(array: $rows, column_key: 'n');
$expect = array('a', 'b');
$match = is_array($r) && array_values($r) === $expect;
echo $match ? "array:OK\n" : ('array:'.var_export($r, true)."\n");
exit($ok && $match ? 0 : 1);
