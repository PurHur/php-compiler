<?php
/** AOT-friendly: array_slice/chunk null preserve_keys (#31442). */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$s = array_slice([1, 2, 3], 0, 1, null);
echo 'slice:', implode(',', $s), "\n";
$c = array_chunk([1, 2], 1, null);
echo 'chunk:', count($c), ':', implode(',', $c[0]), ':', implode(',', $c[1]), "\n";
