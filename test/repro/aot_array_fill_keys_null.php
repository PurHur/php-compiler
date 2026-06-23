<?php
$a = array_fill_keys(array('foo', 'bar'), 'baz');
echo $a['foo'], '|', $a['bar'], "\n";
$b = array_fill_keys(array(0, 1), 'x');
echo $b[0], '|', $b[1], "\n";
$c = array_fill_keys(array(null), 'y');
echo $c[''], "\n";
