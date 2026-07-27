<?php
// #24033 — array_fill_keys bool false key is "" on Zend, not int 0
error_reporting(E_ALL);
$a = array_fill_keys([true, false], 'v');
foreach (array_keys($a) as $k) {
    echo gettype($k), ':', var_export($k, true), "\n";
}
echo 'isset_empty=', isset($a['']) ? 'Y' : 'N', "\n";
echo 'key_exists_0=', array_key_exists(0, $a) ? 'Y' : 'N', "\n";
echo 'literal_false_key=', gettype(array_keys([false => 'x'])[0]), ':', var_export(array_keys([false => 'x'])[0], true), "\n";
