<?php
error_reporting(E_ALL);
$s = 'ab';
foreach (['foo', new stdClass, [1], '0', '1'] as $i => $dim) {
    $label = is_object($dim) ? get_class($dim) : (is_array($dim) ? 'array' : var_export($dim, true));
    try {
        $v = $s[$dim];
        echo "read[$label]=", var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo "read[$label]=", get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    $s['foo'] = 'z';
    echo "write=", $s, "\n";
} catch (Throwable $e) {
    echo "write=", get_class($e), ':', $e->getMessage(), "\n";
}
echo 'isset[foo]=', var_export(isset($s['foo']), true), "\n";
echo 'empty[foo]=', var_export(empty($s['foo']), true), "\n";
echo 'isset[0]=', var_export(isset($s['0']), true), "\n";
