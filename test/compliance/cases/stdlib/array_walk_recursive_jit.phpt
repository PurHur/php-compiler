--TEST--
stdlib array_walk_recursive() JIT string callback (#3111)
--FILE--
<?php
$a = ['x' => ['y' => ' hi '], 'z' => ' lo '];
$ok = array_walk_recursive($a, 'trim');
echo $ok ? 'ok' : 'fail', "\n";
echo $a['x']['y'], '|', $a['z'], "\n";
--EXPECT--
ok
 hi | lo 
