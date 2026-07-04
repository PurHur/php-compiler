--TEST--
stdlib array_merge_recursive() scalar vs array value promotes under key (#15979)
--FILE--
<?php
$r1 = array_merge_recursive(['a' => 1], ['a' => 2]);
$r2 = array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]]);
$r3 = array_merge_recursive(['a' => 1], ['a' => [2]]);
echo $r1['a'][0], "\n";
echo $r1['a'][1], "\n";
echo $r2['a']['x'], "\n";
echo $r2['a']['y'], "\n";
echo $r3['a'][0], "\n";
echo $r3['a'][1], "\n";
--EXPECT--
1
2
1
2
1
2
