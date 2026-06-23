--TEST--
stdlib count() inline nested array literal + COUNT_RECURSIVE (issue #10566, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$n = count([1, [2, 3]], COUNT_RECURSIVE);
echo "count={$n}\n";
?>
--EXPECT--
count=4
