--TEST--
SimpleXMLElement children($ns)->attributes() first child / empty null (#25148, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r xmlns:a="urn:a"><a:c b="1" c="2">t</a:c><d id="x"/></r>');
$attrs = $x->children('urn:a')->attributes();
echo 'count=', count($attrs), "\n";
foreach ($attrs as $k => $v) {
    echo "$k=$v\n";
}

$plain = simplexml_load_string('<r><c a="1">x</c><d b="2">y</d></r>');
$pa = $plain->children()->attributes();
echo 'plain_count=', count($pa), "\n";
foreach ($pa as $k => $v) {
    echo "plain $k=$v\n";
}

$empty = simplexml_load_string('<r xmlns:a="urn:a"><y/></r>');
$none = $empty->children('urn:a')->attributes();
echo 'empty=', var_export($none, true), "\n";

$missing = simplexml_load_string('<r><a/></r>')->missing->attributes();
echo 'missing=', var_export($missing, true), "\n";
?>
--EXPECT--
count=2
b=1
c=2
plain_count=1
plain a=1
empty=NULL
missing=NULL
