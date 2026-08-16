--TEST--
AOT: array_slice/chunk(null $preserve_keys) soft DEP+coerce (#31442; DEP on stderr)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// Avoid var_export (thin AOT #26855) and array_reverse (pre-existing AOT IR verify).
$s = array_slice([1, 2, 3], 0, 1, null);
echo 'slice:', implode(',', $s), "\n";
$c = array_chunk([1, 2], 1, null);
echo 'chunk:', count($c), ':', implode(',', $c[0]), ':', implode(',', $c[1]), "\n";
--EXPECT--
slice:1
chunk:2:1:2
