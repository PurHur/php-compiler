--TEST--
SimpleXMLElement foreach over attributes() view (#19351, ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<r a="1" b="2"/>');
$out = [];
foreach ($x->attributes() as $k => $v) {
    $out[] = $k.'='.(string) $v;
}
echo implode(',', $out), "\n";
echo count($x->attributes()), "\n";
echo (string) $x->attributes()['a'], "\n";
--EXPECT--
a=1,b=2
2
1
