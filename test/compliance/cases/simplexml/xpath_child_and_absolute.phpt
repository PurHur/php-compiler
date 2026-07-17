--TEST--
SimpleXMLElement::xpath absolute and relative child queries (#19321, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = simplexml_load_string('<root><a>1</a><a>2</a></root>');
foreach (['/root/a', 'a'] as $q) {
    $r = $xml->xpath($q);
    echo $q, ' count=', count($r);
    if (count($r) > 0) {
        echo ' first=', (string) $r[0];
    }
    echo "\n";
}
?>
--EXPECT--
/root/a count=2 first=1
a count=2 first=1
