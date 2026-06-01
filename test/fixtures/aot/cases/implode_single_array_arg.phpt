--TEST--
AOT: implode() with single array argument (empty glue)
--FILE--
<?php
echo implode(['a', 'b']), "\n";
echo join([0 => 'x', 1 => 'y']), "\n";
--EXPECT--
ab
xy
