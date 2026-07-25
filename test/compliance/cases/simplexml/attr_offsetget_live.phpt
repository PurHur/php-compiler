--TEST--
SimpleXMLElement attribute offsetGet handle stays live after write (#22654, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r a="1"/>');
$a = $x['a'];
$x['a'] = '2';
echo (string) $a, ' ', (string) $x['a'], "\n";

$attrs = $x->attributes();
$cap = $attrs['a'];
$x['a'] = '3';
echo (string) $cap, ' ', (string) $attrs['a'], "\n";

$held = null;
foreach ($x->attributes() as $k => $v) {
    if ($k === 'a') {
        $held = $v;
    }
}
$x['a'] = '4';
echo (string) $held, "\n";
echo json_encode($a), "\n";
--EXPECT--
2 2
3 3
4
{"0":"4"}
