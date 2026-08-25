--TEST--
SimpleXMLElement isset/empty attribute dims under AOT (#34555)
--FILE--
<?php
$xml = simplexml_load_string('<r a="1" b=""><c/></r>');
foreach (['a', 'b', 'missing', 0] as $k) {
    echo $k, ':', isset($xml[$k]) ? 'I' : 'i', empty($xml[$k]) ? 'E' : 'e', '|';
}
echo "\n";
--EXPECT--
a:Ie|b:IE|missing:iE|0:Ie|
