<?php
// #24034 — array_combine bool false key is "" on Zend, not int 0
error_reporting(E_ALL);
$a = array_combine([true, false], ['a', 'b']);
foreach (array_keys($a) as $k) {
    echo gettype($k), ':', var_export($k, true), "\n";
}
echo 'isset_empty=', isset($a['']) ? 'Y' : 'N', "\n";
echo 'vals=', $a[1], '|', $a[''], "\n";
