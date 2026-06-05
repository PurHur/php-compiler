--TEST--
stdlib array_replace_recursive() nested key overlay (#6022)
--FILE--
<?php
$a = ['k' => ['x' => 1, 'y' => 2]];
$b = ['k' => ['y' => 9]];
$r = array_replace_recursive($a, $b);
echo $r['k']['x'], "\n";
echo $r['k']['y'], "\n";
--EXPECT--
1
9
