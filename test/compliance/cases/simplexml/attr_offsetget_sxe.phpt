--TEST--
SimpleXMLElement ArrayAccess attribute offsetGet returns SXE (#22733, ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<r a="1" b="2"/>');
$a = $x['a'];
echo gettype($a), "\n";
echo get_class($a), "\n";
echo json_encode($a), "\n";
echo (string) $a, "\n";
$attrs = $x->attributes();
echo gettype($attrs['a']), "\n";
echo json_encode($attrs['a']), "\n";
echo gettype($attrs[0]), "\n";
echo json_encode($attrs[0]), "\n";
echo (string) $attrs[1], "\n";
--EXPECT--
object
SimpleXMLElement
{"0":"1"}
1
object
{"0":"1"}
object
{"0":"1"}
2
