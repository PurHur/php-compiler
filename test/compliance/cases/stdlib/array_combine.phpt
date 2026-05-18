--TEST--
stdlib array_combine()
--FILE--
<?php
$c = array_combine(array('a', 'b'), array(1, 2));
echo $c['a'], '|', $c['b'], "\n";
$d = array_combine(array(0, 1), array('x', 'y'));
echo $d[0], '|', $d[1], "\n";
echo array_combine(array(1), array(1, 2)) === false ? 'fail' : 'ok', "\n";
--EXPECT--
1|2
x|y
fail
