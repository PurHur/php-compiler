--TEST--
json_encode() SimpleXMLElement text leaf is JSON object {"0":...} (#22730, #22733)
--FILE--
<?php
$x = new SimpleXMLElement('<r><y>2</y></r>');
echo json_encode($x), "\n";
echo json_encode($x->y), "\n";
$a = new SimpleXMLElement('<r a="1"/>');
echo json_encode($a['a']), "\n";
--EXPECT--
{"y":"2"}
{"0":"2"}
{"0":"1"}
