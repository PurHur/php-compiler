--TEST--
SimpleXMLElement::asXML() mid-foreach does not rewind under PROFILE=8.4 (php-src 8.4+; #27717)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$xml = simplexml_load_string('<root><a><b>1</b><b>2</b><b>3</b></a></root>');
$nodes = $xml->a->b;
$out = [];
$n = 0;
foreach ($nodes as $nodeData) {
    $out[] = (string) $nodeData;
    $nodes->asXml();
    $nodes->getName();
    (string) $nodes;
    if (++$n > 8) {
        $out[] = 'LOOP';
        break;
    }
}
echo implode(',', $out), "\n";
?>
--EXPECT--
1,2,3
