--TEST--
SimpleXMLElement::getName() + string cast mid-foreach rewind under PROFILE=8.2 (#27717)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$xml = simplexml_load_string('<root><a><b>1</b><b>2</b><b>3</b></a></root>');

$nodes = $xml->a->b;
$out = [];
$n = 0;
foreach ($nodes as $nodeData) {
    $out[] = (string) $nodeData;
    $nodes->getName();
    if (++$n > 8) {
        $out[] = 'LOOP';
        break;
    }
}
echo 'getName:', implode(',', $out), "\n";

$nodes = $xml->a->b;
$out = [];
$n = 0;
foreach ($nodes as $nodeData) {
    $out[] = (string) $nodeData;
    (string) $nodes;
    if (++$n > 8) {
        $out[] = 'LOOP';
        break;
    }
}
echo 'cast:', implode(',', $out), "\n";
?>
--EXPECT--
getName:1,2,2,2,2,2,2,2,2,LOOP
cast:1,2,2,2,2,2,2,2,2,LOOP
