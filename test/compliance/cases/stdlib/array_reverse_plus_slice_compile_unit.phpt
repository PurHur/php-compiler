--TEST--
stdlib array_reverse() with array_slice() in same compile unit (#14042)
--FILE--
<?php
declare(strict_types=1);
function out(string $k, mixed $v): void {
    echo $k.'='.(is_string($v) ? $v : var_export($v, true))."\n";
}
out('array_reverse', array_reverse(['a' => 1, 'b' => 2], true));
out('array_slice', array_slice(['a' => 1, 'b' => 2, 'c' => 3], 1, 2, true));
?>
--EXPECT--
array_reverse=array (
  'b' => 2,
  'a' => 1,
)
array_slice=array (
  'b' => 2,
  'c' => 3,
)
