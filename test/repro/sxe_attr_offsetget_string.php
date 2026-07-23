<?php
$x = new SimpleXMLElement('<r a="1"/>');
$a = $x['a'];
echo 'type=', gettype($a), PHP_EOL;
echo 'class=', (is_object($a) ? get_class($a) : '-'), PHP_EOL;
echo 'json=', json_encode($a), PHP_EOL;
echo 'str=', (string)$a, PHP_EOL;
$attrs = $x->attributes();
$b = $attrs['a'];
echo 'attrs_type=', gettype($b), PHP_EOL;
echo 'attrs_json=', json_encode($b), PHP_EOL;
$c = $attrs[0];
echo 'idx_type=', gettype($c), PHP_EOL;
echo 'idx_json=', json_encode($c), PHP_EOL;
