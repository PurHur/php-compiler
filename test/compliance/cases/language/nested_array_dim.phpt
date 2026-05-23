--TEST--
Chained array dim fetch on nested hashtables (issue #827)
--FILE--
<?php
$a = ['outer' => ['inner' => 42]];
echo $a['outer']['inner'], "\n";
--EXPECT--
42
